<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    private const float MAX_SECONDS = 1.5;

    // 2x input at linear scaling costs 2x time; 2.5x allows ~25% scheduling
    // noise while still failing a persistently superlinear pipeline. The old
    // 3.0x blessed ~1.5x creep per doubling, letting quadratic drift merge.
    private const float MAX_SCALING_RATIO = 2.5;

    private const int SCALING_SAMPLES = 3;

    // Fixed-corpus throughput floor: order-of-magnitude collapse trips here
    // even when the scaling ratio stays linear (a uniformly 10x slowdown is
    // still linear). Sized ~4x below measured throughput (~8000 names/sec)
    // so loaded CI hosts do not flake a healthy pipeline.
    private const float MIN_CORPUS_NAMES_PER_SECOND = 2000.0;

    private const int MAX_INPUT_BYTES = 1024 * 1024;

    private const int MAX_INPUT_TOKENS = 65536;

    public function testCombinedInitialExpansionRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('AB ', $size) . 'Smith',
        );
    }

    public function testMultiwordSalutationMappingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('the honorable ', $size) . 'John Smith',
        );
    }

    public function testJointSalutationValidationRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('Mr. and ', $size) . 'Mrs. Brad Smith',
            false,
            2000,
            4000,
        );
    }

    public function testSurnameFirstSalutationPeelingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('Dr ', $size) . 'Kim Jong',
            true,
        );
    }

    public function testNestedNicknameDepthRemainsBoundedAtBatchScale(): void
    {
        // median CPU time over samples: a single wall-clock sample flakes on
        // shared CI, while the median only trips on a persistent slowdown.
        $input = str_repeat('( ', 16000) . str_repeat(') ', 16000) . 'Smith';
        $elapsed = $this->medianCpuSeconds(
            static function () use ($input): void {
                (new Parser())->parse($input);
            },
        );

        $this->assertLessThan(0.2, $elapsed);
    }
    public function testCommaHeavyInputUsesBoundedWorkingMemory(): void
    {
        $input = 'Smith,' . str_repeat(',', 500000);
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testMaximumSizeInteriorTokenUsesBoundedWorkingMemory(): void
    {
        $input = 'John ' . str_repeat('A', self::MAX_INPUT_BYTES - 11) . ' Smith';
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testMaximumCamelcaseScanRemainsFastWithoutPcreJit(): void
    {
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $elapsed = $this->medianCpuSeconds(
                static function (): void {
                    $word = 'A' . str_repeat('a', 1023);
                    for ($i = 0; $i < 250; ++$i) {
                        (new Lastname($word))->normalize();
                    }
                },
            );
        } finally {
            if ($jit !== false) {
                ini_set('pcre.jit', $jit);
            }
        }

        $this->assertLessThan(0.25, $elapsed);
    }

    public function testMaximumCommonPrefixDelimiterScanRemainsBounded(): void
    {
        $delimiters = [];
        for ($i = 0; $i < 32; ++$i) {
            $delimiter = str_repeat('a', 62) . sprintf('%02d', $i);
            $delimiters[$delimiter] = $delimiter;
        }

        $parser = (new Parser())->setNicknameDelimiters($delimiters);
        $input = str_repeat('a', 4094) . ',X';
        $elapsed = $this->medianCpuSeconds(
            static function () use ($parser, $input): void {
                $parser->parse($input);
            },
        );

        $this->assertLessThan(0.15, $elapsed);
    }

    public function testRejectsInputOverByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A', self::MAX_INPUT_BYTES + 1));
    }

    public function testRejectsInputOverTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A ', self::MAX_INPUT_TOKENS) . 'A');
    }

    public function testAcceptsExactCommaTokenBudget(): void
    {
        $input = str_repeat('A,', self::MAX_INPUT_TOKENS - 1) . 'A';

        $name = (new Parser())->parse($input);

        $this->assertSame($input, $name->getSource());
        // the exact-budget input parses to fields, not just a source echo:
        // the token stream still yields a firstname and a lastname.
        $this->assertSame('A', $name->getFirstname());
        $this->assertSame('A', $name->getLastname());
    }

    public function testFixedCorpusThroughputFloor(): void
    {
        // realistic mixed batch (joint, comma, credential, nickname,
        // particle, multibyte rows); median CPU time over samples so a
        // loaded CI host does not flake a healthy pipeline.
        $corpus = [
            'James Norrington',
            'Mr. and Mrs. Brad Smith',
            'Smith, John, MD, PhD',
            'Herr Schmidt',
            'Kim Jong Un',
            'Jimmy (Bubba) Smith',
            'Elizabeth De La Torre',
            'Dr. Jonathan Smith',
            'Williams, Hank, Jr.',
            'Thái Quốc Nguyễn',
        ];
        $elapsed = $this->medianCpuSeconds(
            static function () use ($corpus): void {
                foreach ($corpus as $input) {
                    (new Parser())->parse($input)->toArray();
                }
            },
            5,
        );

        $this->assertGreaterThan(0, $elapsed);
        $this->assertGreaterThan(
            self::MIN_CORPUS_NAMES_PER_SECOND,
            count($corpus) / $elapsed,
            'corpus throughput names/sec',
        );
    }

    public function testRejectsInputOverCommaTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A,', self::MAX_INPUT_TOKENS) . 'A');
    }

    public function testExactByteLimitEmptyCommaRunUsesBoundedMemory(): void
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';

            $name = (new Iliaal\NameParser\Parser())->parse(str_repeat(',', 1048576));

            exit($name->getFullName() === '' ? 0 : 1);
            PHP;
        $this->assertConstrainedPhpSucceeds($code);
    }

    public function testConfidenceTitleCollisionCommaRunUsesBoundedMemory(): void
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';

            $prefix = 'Lord Ashcroft';
            $input = $prefix . str_repeat(',', 1048576 - strlen($prefix));
            $result = Iliaal\NameParser\Confidence::assess($input);

            exit($result['ambiguous'] ? 0 : 1);
            PHP;
        $this->assertConstrainedPhpSucceeds($code);
    }

    private function assertConstrainedPhpSucceeds(string $code): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
        );
        if (! is_resource($process)) {
            self::fail('Unable to start the constrained-memory PHP process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame(0, $status);
    }

    /**
     * The sizes are operation counts (tokens), not bytes, so the ratio pins
     * work scaling: doubling the tokens must not more than 2.5x the CPU.
     * Samples interleave small/large and take the median to starve flakes.
     *
     * @param  callable(int): string  $input
     */
    private function assertLinearScaling(
        callable $input,
        bool $surnameFirst = false,
        int $smallSize = 16000,
        int $largeSize = 32000,
    ): void {
        $smallInput = $input($smallSize);
        $largeInput = $input($largeSize);
        $this->parseCpuSeconds($smallInput, $surnameFirst);
        $this->parseCpuSeconds($largeInput, $surnameFirst);

        $smallSamples = [];
        $largeSamples = [];
        for ($sample = 0; $sample < self::SCALING_SAMPLES; $sample++) {
            if ($sample % 2 === 0) {
                $smallSamples[] = $this->parseCpuSeconds($smallInput, $surnameFirst);
                $largeSamples[] = $this->parseCpuSeconds($largeInput, $surnameFirst);
            } else {
                $largeSamples[] = $this->parseCpuSeconds($largeInput, $surnameFirst);
                $smallSamples[] = $this->parseCpuSeconds($smallInput, $surnameFirst);
            }
        }

        $small = $this->median($smallSamples);
        $large = $this->median($largeSamples);

        $this->assertLessThan(self::MAX_SECONDS, $large);
        $this->assertLessThan(
            ($small * self::MAX_SCALING_RATIO) + 0.005,
            $large,
        );
    }

    /**
     * @param  non-empty-list<float>  $samples
     */
    private function median(array $samples): float
    {
        sort($samples);

        return $samples[intdiv(count($samples), 2)];
    }

    private function parseCpuSeconds(string $input, bool $surnameFirst = false): float
    {
        $started = getrusage();
        $parser = (new Parser())->setSurnameFirst($surnameFirst);
        $parser->parse($input)->toArray();
        $finished = getrusage();
        if ($started === false || $finished === false) {
            self::fail('Unable to read process CPU usage.');
        }

        return $this->cpuSeconds($finished) - $this->cpuSeconds($started);
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function cpuSeconds(array $usage): float
    {
        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'])
            + (($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1_000_000);
    }

    /**
     * Median CPU time over repeated runs of arbitrary work: CPU time (not
     * wall clock) so scheduling jitter on shared CI cannot flake the gate,
     * and the median so one slow sample cannot fail a healthy pipeline.
     */
    private function medianCpuSeconds(callable $work, int $samples = 3): float
    {
        $timings = [];
        for ($sample = 0; $sample < $samples; ++$sample) {
            $started = getrusage();
            $work();
            $finished = getrusage();
            if ($started === false || $finished === false) {
                self::fail('Unable to read process CPU usage.');
            }

            $timings[] = $this->cpuSeconds($finished) - $this->cpuSeconds($started);
        }
        if ($timings === []) {
            self::fail('No timing samples were collected.');
        }

        return $this->median($timings);
    }
}
