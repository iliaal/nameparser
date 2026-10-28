<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;

/**
 * Advisory pass: flags inputs where a token collides with a credential and the
 * casing signal is uninformative (uniform-case input, or a lowercase token), so
 * an import pipeline can route the row to manual review.
 */
class Confidence
{
    /**
     * When suffixes are supplied, only collisions present in that parser's
     * configured dictionaries contribute to the result.
     *
     * Nickname delimiters and whitespace mirror the Parser configuration the
     * input was parsed with (Name::getConfidence() forwards the stored
     * values), so decoration mapping and comma splitting agree with the
     * parse. Connectors likewise use the parse-time dictionary; null or an empty
     * array uses the default English connectors. A whitespace set that is
     * not valid UTF-8 is rejected, as Parser::setWhitespace() rejects it.
     *
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<int|string, string>|null  $salutations
     * @param  list<string>|null  $tokens
     * @param  array<string, string>|null  $nicknameDelimiters
     * @param  array<string, string>|null  $connectors
     * @return array{ambiguous: bool, notes: list<string>}
     *
     * @throws \InvalidArgumentException when the whitespace set is not valid UTF-8
     */
    public static function assess(
        string $original,
        ?array $suffixes = null,
        ?array $salutations = null,
        ?array $tokens = null,
        ?array $nicknameDelimiters = null,
        ?string $whitespace = null,
        ?array $connectors = null,
    ): array {
        Text::assertInputByteBudget($original);
        if ($whitespace !== null) {
            Text::assertValidWhitespace($whitespace);
        }
        $validUtf8 = mb_check_encoding($original, 'UTF-8');
        $normalized = self::normalize($original, $whitespace);

        if ($tokens !== null) {
            self::assertSuppliedTokenBudgets($tokens);
            self::assertOriginalTokenBudget($normalized, self::delimiters($nicknameDelimiters));
        }

        if (! $validUtf8) {
            if ($tokens === null) {
                $tokens = self::tokenize($normalized, self::delimiters($nicknameDelimiters));
                Text::assertInputTokenCount(count($tokens));
            }

            return ['ambiguous' => true, 'notes' => ['input is not valid UTF-8']];
        }

        if ($tokens === null) {
            $tokens = self::tokenize($normalized, self::delimiters($nicknameDelimiters));
            Text::assertInputTokenCount(count($tokens));
        }

        // Use the parser's token-level casing rules, including caseless scripts.
        $uniformUpper = Text::isUniformUpperTokens($tokens);
        $uniformLower = self::isUniformLowerTokens($tokens);

        /** @var array<string, true> $notes */
        $notes = [];
        foreach ($tokens as $token) {
            $key = Text::key($token);
            if (! isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                continue;
            }

            if ($suffixes !== null && ! array_key_exists($key, $suffixes)) {
                continue;
            }

            $tokenLower = Text::isLowerCase($token);

            if ($uniformUpper) {
                // Uniform caps hide name/credential collisions; clean credentials stay unflagged.
                if (isset(SuffixMapper::NAME_LEANING_KEYS[$key])
                    || isset(SuffixMapper::SURNAME_COLLIDING_KEYS[$key])) {
                    $notes["'{$token}' could be a name or a credential; input casing is uniform"] = true;
                }
            } elseif ($uniformLower) {
                $notes["'{$token}' could be a name or a credential; input casing is uniform"] = true;
            } elseif ($tokenLower) {
                $notes["'{$token}' could be a name or a credential; token is lowercase"] = true;
            }
        }

        $lead = $tokens[0] ?? '';
        $key = Text::key($lead);
        if ($lead !== ''
            && isset(SalutationMapper::NAME_COLLIDING_KEYS[$key])
            && ($salutations === null || array_key_exists($key, $salutations))) {
            // Decorations do not resolve title/name ambiguity; a comma needs
            // name-bearing content on both sides.
            $titleRunDecides = false;
            $nameTokens = self::rawNameTokens(self::mapDecorations(
                $tokens,
                $suffixes,
                $nicknameDelimiters,
                $salutations,
                $titleRunDecides,
                $connectors,
            ));
            if (! $titleRunDecides
                && count($nameTokens) === 1
                && ! self::hasDecidingStructuralComma($normalized, $suffixes, $nicknameDelimiters, $salutations, $connectors)) {
                $notes["'{$lead}' could be a name or a salutation; nothing in the input decides it"] = true;
            }
        }

        return ['ambiguous' => $notes !== [], 'notes' => array_keys($notes)];
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function assertSuppliedTokenBudgets(array $tokens): void
    {
        Text::assertInputTokenCount(count($tokens));

        $bytes = 0;
        foreach ($tokens as $token) {
            $bytes += strlen($token);
            if ($bytes > Text::MAX_INPUT_BYTES) {
                throw new \LengthException(
                    'Name tokens exceed the ' . Text::MAX_INPUT_BYTES . '-byte limit.',
                );
            }
        }
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     */
    private static function assertOriginalTokenBudget(
        string $normalized,
        array $nicknameDelimiters,
    ): void {
        if (strlen($normalized) < (Text::MAX_INPUT_TOKENS * 2) + 1) {
            return;
        }

        Text::assertInputTokenCount(count(self::tokenize($normalized, $nicknameDelimiters)));
    }

    /**
     * @param  array<string, string>|null  $delimiters
     * @return array<string, string>
     */
    private static function delimiters(?array $delimiters): array
    {
        return $delimiters === null || $delimiters === []
            ? NicknameMapper::DEFAULT_DELIMITERS
            : $delimiters;
    }

    private static function normalize(string $original, ?string $whitespace): string
    {
        $whitespace ??= " \r\n\t";

        $normalized = mb_scrub($original, 'UTF-8');
        // Match Parser's cap before normalization can shrink scrubbed input.
        Text::assertInputByteBudget($normalized);
        $normalized = trim(Text::replaceLineSeparators($normalized));
        $normalized = str_replace("\x00", '', $normalized);

        if ($whitespace === '') {
            return trim(Text::stripControlChars($normalized, true));
        }

        $pattern = '/[' . preg_quote($whitespace, '/') . ']+/u';
        $normalized = preg_replace($pattern, ' ', $normalized) ?? $normalized;

        return trim(Text::stripControlChars(trim($normalized)));
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     * @return list<string>
     */
    private static function tokenize(string $normalized, array $nicknameDelimiters): array
    {
        if (! str_contains($normalized, ',')) {
            return self::tokenizeWords($normalized);
        }
        $delimiters = Text::sanitizeNicknameDelimiters($nicknameDelimiters);
        if ($delimiters === []) {
            return self::tokenizeCommaSegments($normalized);
        }

        foreach ($delimiters as $open => $close) {
            if (strlen((string) $open) !== 1 || strlen((string) $close) !== 1) {
                return self::tokenizeWithStructuralSplitter($normalized, $nicknameDelimiters);
            }
        }

        return self::tokenizeAsciiDelimiters($normalized, $delimiters);
    }

    /**
     * @return list<string>
     */
    private static function tokenizeCommaSegments(string $normalized): array
    {
        $tokens = [];
        $length = strlen($normalized);
        $segmentStart = 0;
        for ($position = 0; $position <= $length; $position++) {
            if ($position !== $length && $normalized[$position] !== ',') {
                continue;
            }

            array_push($tokens, ...self::tokenizeWords(substr($normalized, $segmentStart, $position - $segmentStart)));
            Text::assertInputTokenCount(count($tokens));
            $segmentStart = $position + 1;
        }

        return $tokens;
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     * @return list<string>
     */
    private static function tokenizeWithStructuralSplitter(string $normalized, array $nicknameDelimiters): array
    {
        $tokens = [];
        foreach (StructuralCommaSplitter::split($normalized, $nicknameDelimiters) as $segment) {
            array_push($tokens, ...self::tokenizeWords($segment));
            Text::assertInputTokenCount(count($tokens));
        }

        return $tokens;
    }

    /**
     * @param  array<string, string>  $delimiters
     * @return list<string>
     */
    private static function tokenizeAsciiDelimiters(string $normalized, array $delimiters): array
    {
        $pairs = [];
        $symmetric = [];
        foreach ($delimiters as $open => $close) {
            $open = (string) $open;
            $close = (string) $close;
            if ($open === $close) {
                $symmetric[$open] = true;
            } else {
                $pairs[$open] = $close;
            }
        }

        $length = strlen($normalized);
        $endPositions = [];
        foreach ($symmetric as $delimiter => $_) {
            $endPositions[$delimiter] = [];
        }

        $tokenStart = null;
        for ($position = 0; $position <= $length; $position++) {
            $byte = $position < $length ? $normalized[$position] : null;
            if ($byte !== null && $byte !== ' ' && $byte !== ',') {
                $tokenStart ??= $position;

                continue;
            }

            if ($tokenStart === null) {
                continue;
            }

            $closerStart = $position - 1;
            foreach ($symmetric as $delimiter => $_) {
                $delimiter = (string) $delimiter;
                if ($closerStart < $tokenStart || $normalized[$closerStart] !== $delimiter) {
                    continue;
                }

                if ($position - $tokenStart >= 2 && $normalized[$tokenStart] === $delimiter) {
                    continue;
                }

                $endPositions[$delimiter][] = $closerStart;
            }
            $tokenStart = null;
        }
        $candidateIndexes = [];

        /** @var list<array{0: string, 1: bool}> $closers */
        $closers = [];
        /** @var list<list<int>> $pendingCommas */
        $pendingCommas = [];
        /** @var array<string, true> $openSymmetric */
        $openSymmetric = [];
        /** @var array<int, true> $mask */
        $mask = [];
        $trackedCommas = 0;

        for ($position = 0; $position < $length;) {
            $depth = count($closers);
            $byte = $normalized[$position];
            if ($depth > 0) {
                [$close, $isSymmetric] = $closers[$depth - 1];
                if ($byte === $close
                    && (! $isSymmetric || self::isAsciiTokenBoundary($normalized[$position + 1] ?? null))) {
                    array_pop($closers);
                    if ($isSymmetric) {
                        array_pop($openSymmetric);
                    }
                    foreach (array_pop($pendingCommas) ?? [] as $commaPosition) {
                        $mask[$commaPosition] = true;
                    }
                    ++$position;

                    continue;
                }
            }

            $canOpen = $depth < 128 && $trackedCommas < 65536;
            if ($canOpen && isset($pairs[$byte])) {
                $closers[] = [$pairs[$byte], false];
                $pendingCommas[] = [];
                ++$position;

                continue;
            }

            $hasCloser = false;
            if ($canOpen
                && isset($symmetric[$byte])
                && ! isset($openSymmetric[$byte])
                && self::isAsciiTokenBoundary($position > 0 ? $normalized[$position - 1] : null)) {
                $candidateIndex = $candidateIndexes[$byte] ?? 0;
                $hasCloser = self::hasNextCandidate(
                    $endPositions[$byte] ?? [],
                    $candidateIndex,
                    $position + 1,
                );
                $candidateIndexes[$byte] = $candidateIndex;
            }

            if ($hasCloser) {
                $closers[] = [$byte, true];
                $openSymmetric[$byte] = true;
                $pendingCommas[] = [];
                ++$position;

                continue;
            }

            if ($byte === ',' && $depth > 0 && $trackedCommas < 65536) {
                $pendingCommas[$depth - 1][] = $position;
                ++$trackedCommas;
            }
            ++$position;
        }

        $tokens = [];
        $segmentStart = 0;
        for ($position = 0; $position <= $length; $position++) {
            if ($position !== $length && ($normalized[$position] !== ',' || isset($mask[$position]))) {
                continue;
            }

            array_push($tokens, ...self::tokenizeWords(substr($normalized, $segmentStart, $position - $segmentStart)));
            Text::assertInputTokenCount(count($tokens));
            $segmentStart = $position + 1;
        }

        return $tokens;
    }

    private static function isAsciiTokenBoundary(?string $character): bool
    {
        return $character === null || $character === ' ' || $character === ',';
    }

    /**
     * @param  list<int>  $candidates
     */
    private static function hasNextCandidate(array $candidates, int &$index, int $minimum): bool
    {
        while (isset($candidates[$index]) && $candidates[$index] < $minimum) {
            $index++;
        }

        return isset($candidates[$index]);
    }

    /**
     * @return list<string>
     */
    private static function tokenizeWords(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $tokenStart = null;
        for ($position = 0; $position <= $length; $position++) {
            if ($position !== $length && $text[$position] !== ' ') {
                if ($tokenStart === null) {
                    $tokenStart = $position;
                }

                continue;
            }

            if ($tokenStart === null) {
                continue;
            }

            $tokens[] = substr($text, $tokenStart, $position - $tokenStart);
            Text::assertInputTokenCount(count($tokens));
            $tokenStart = null;
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function isUniformLowerTokens(array $tokens): bool
    {
        $hasCased = false;

        foreach ($tokens as $token) {
            if (! Text::isCased($token)) {
                continue;
            }

            $hasCased = true;

            if (! Text::isLowerCase($token)) {
                return false;
            }
        }

        return $hasCased;
    }

    /**
     * Mirrors the single-segment pipeline's decoration mappers, so a wrapped
     * lead title, a second title, or an unattributed connector is classified
     * the way the parse classifies it. The lead is the title-or-name subject
     * under test, so it never counts as one of the remaining name tokens.
     *
     * @param  list<string>  $tokens
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<string, string>|null  $nicknameDelimiters
     * @param  array<int|string, string>|null  $salutations
     * @param  array<string, string>|null  $connectors
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private static function mapDecorations(
        array $tokens,
        ?array $suffixes,
        ?array $nicknameDelimiters = null,
        ?array $salutations = null,
        bool &$titleRunDecides = false,
        ?array $connectors = null,
    ): array {
        $suffixes ??= English::SUFFIXES;
        $delimiters = self::delimiters($nicknameDelimiters);
        $salutationMapper = new SalutationMapper(
            $salutations ?? English::SALUTATIONS,
            0,
            false,
            $suffixes,
            $delimiters,
            $connectors ?? [],
        );
        $suffixMapper = new SuffixMapper($suffixes, false, 2, $delimiters);
        $nicknameMapper = new NicknameMapper($delimiters);

        $parts = $suffixMapper->map($nicknameMapper->map($suffixMapper->map($salutationMapper->map($tokens))));
        $titleRunDecides = ($parts[0] ?? null) instanceof Salutation && self::absorbsDecidingTitle($parts);
        if (is_string($parts[0] ?? null) || ($parts[0] ?? null) instanceof Salutation) {
            unset($parts[0]);
        }

        return $parts;
    }

    /**
     * A connector or an unambiguous title in the honorific run after the
     * lead shows the lead is a title too ('Lord and Lady Smith',
     * 'Lord Rev. Smith'); a run of name-colliding titles decides nothing.
     *
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     */
    private static function absorbsDecidingTitle(array $parts): bool
    {
        for ($index = 1; ($parts[$index] ?? null) instanceof Salutation; $index++) {
            $part = $parts[$index];
            if ($part instanceof SalutationConnector
                || ! isset(SalutationMapper::NAME_COLLIDING_KEYS[Text::key($part->getValue())])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     * @return list<string>
     */
    private static function rawNameTokens(array $parts): array
    {
        return array_values(array_filter(
            $parts,
            static fn(mixed $part): bool => is_string($part) && Text::letters($part) !== '',
        ));
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     */
    private static function hasNicknamePart(array $parts): bool
    {
        foreach ($parts as $part) {
            if ($part instanceof Nickname) {
                return true;
            }
        }

        return false;
    }

    /**
     * A comma decides name order only when names occur on both sides.
     * Map each segment once to keep delimiter-heavy input linear. Honorific
     * connectors in the combined given-name tail do not count as names.
     *
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<string, string>|null  $nicknameDelimiters
     * @param  array<int|string, string>|null  $salutations
     * @param  array<string, string>|null  $connectors
     */
    private static function hasDecidingStructuralComma(
        string $normalized,
        ?array $suffixes,
        ?array $nicknameDelimiters = null,
        ?array $salutations = null,
        ?array $connectors = null,
    ): bool {
        $segments = StructuralCommaSplitter::split($normalized, self::delimiters($nicknameDelimiters));
        if (count($segments) < 2) {
            return false;
        }

        ['suffix' => $suffixMapper, 'nickname' => $nicknameMapper] = AbstractMapper::decorationAnalyzers(
            $suffixes ?? English::SUFFIXES,
            self::delimiters($nicknameDelimiters),
        );

        $surnameParts = $suffixMapper->map($nicknameMapper->map(self::tokenizeWords(array_shift($segments))));
        // The surname segment parse promotes nicknames to Lastname when no
        // raw name remains ('"Lord", Smith' binds surname Lord).
        if (self::rawNameTokens($surnameParts) === [] && ! self::hasNicknamePart($surnameParts)) {
            return false;
        }

        $tailParts = [];
        foreach ($segments as $segment) {
            foreach ($suffixMapper->map($nicknameMapper->map(self::tokenizeWords($segment))) as $part) {
                $tailParts[] = $part;
            }
        }

        $connectorDictionary = $connectors ?: SalutationMapper::DEFAULT_CONNECTORS;
        $hasConnector = false;
        foreach ($tailParts as $part) {
            if (is_string($part) && isset($connectorDictionary[Text::key($part)])) {
                $hasConnector = true;
                break;
            }
        }

        if (! $hasConnector) {
            return self::rawNameTokens($tailParts) !== [];
        }

        // Match the comma-tail salutation pass: a colliding title such as Lady
        // remains a name, while an unattributed connector and following Rev.
        // do not. Keep the tail together so this also holds across commas.
        $salutationMapper = new SalutationMapper(
            $salutations ?? English::SALUTATIONS,
            requireRemainder: true,
            suffixes: $suffixes ?? English::SUFFIXES,
            nicknameDelimiters: self::delimiters($nicknameDelimiters),
            connectors: $connectors ?? [],
        );

        return self::rawNameTokens($suffixMapper->map($salutationMapper->map($tailParts))) !== [];
    }
}
