<?php

namespace Iliaal\NameParser;

final class StructuralCommaSplitter
{
    public const string COMMA_PLACEHOLDER = "\x00";

    /**
     * Past these scan caps, further openers are literal and commas are structural.
     */
    private const int MAX_MASK_NESTING_DEPTH = 128;

    private const int MAX_MASKED_COMMAS = 65536;

    /**
     * @param  array<string, string>  $nicknameDelimiters
     * @return list<string>
     */
    public static function split(string $name, array $nicknameDelimiters): array
    {
        if (! str_contains($name, ',')) {
            return [$name];
        }

        // The placeholder has the same byte width, preserving original offsets.
        $masked = self::mask($name, $nicknameDelimiters);

        $segments = [];
        $hasStructuralComma = false;
        $offset = 0;

        while (($pos = strpos($masked, ',', $offset)) !== false) {
            $hasStructuralComma = true;
            $segment = substr($name, $offset, $pos - $offset);
            if ($segment !== '' || $segments === [] || end($segments) !== '') {
                $segments[] = $segment;
            }

            $offset = $pos + 1;
        }

        if (! $hasStructuralComma) {
            return [$name];
        }

        $segment = substr($name, $offset);
        if ($segment !== '' || end($segments) !== '') {
            $segments[] = $segment;
        }

        if (count($segments) === 1) {
            $segments[] = '';
        }

        return $segments;
    }

    /**
     * replace each comma that falls inside a matched delimiter pair with a
     * placeholder so the comma split leaves the nickname intact. Only spans
     * that actually close are masked; an unmatched opener masks nothing. A
     * symmetric delimiter (quote) opens only at a token start with a token-end
     * closer later, mirroring NicknameMapper, so a mid-token apostrophe
     * (O'Brien) or an elided particle ('t) never shields a comma.
     *
     * @param  array<string, string>  $nicknameDelimiters
     */
    public static function mask(string $name, array $nicknameDelimiters): string
    {
        if (! str_contains($name, ',')) {
            return $name;
        }

        // The character scan changes invalid UTF-8 byte lengths, invalidating split offsets.
        if (! mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }

        [$pairs, $symmetric] = self::splitDelimiters(
            Text::sanitizeNicknameDelimiters($nicknameDelimiters),
        );

        if ($pairs === [] && $symmetric === []) {
            return $name;
        }

        $openerBytes = implode('', array_merge(array_keys($pairs), array_keys($symmetric)));
        if (strpbrk($name, $openerBytes) === false) {
            return $name;
        }

        // ASCII delimiter bytes cannot occur inside valid multibyte characters;
        // scan without allocating a character array.
        if (self::allSingleByteDelimiters($pairs, $symmetric)) {
            return self::maskAscii($name, $pairs, $symmetric);
        }

        // Bound character-array allocation for multibyte delimiters; longer input is unshielded.
        if (strlen($name) > 4096) {
            return $name;
        }

        return self::maskMultibyte($name, $pairs, $symmetric);
    }

    /**
     * @param  array<string, string>  $delimiters
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private static function splitDelimiters(array $delimiters): array
    {
        $pairs = [];
        /** @var array<string, true> $symmetric */
        $symmetric = [];
        foreach ($delimiters as $open => $close) {
            if ($open === '' || $close === '') {
                continue;
            }

            if ($open === $close) {
                $symmetric[$open] = true;
            } else {
                $pairs[$open] = $close;
            }
        }

        return [$pairs, $symmetric];
    }

    /**
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private static function allSingleByteDelimiters(array $pairs, array $symmetric): bool
    {
        foreach ($pairs as $open => $close) {
            if (strlen((string) $open) !== 1 || strlen($close) !== 1) {
                return false;
            }
        }

        foreach ($symmetric as $quote => $_) {
            if (strlen((string) $quote) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * ASCII counterpart of maskMultibyte(), with the same token-boundary rules.
     *
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private static function maskAscii(string $name, array $pairs, array $symmetric): string
    {
        $asciiPairs = [];
        foreach ($pairs as $open => $close) {
            $asciiPairs[(string) $open] = $close;
        }

        $length = strlen($name);

        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        if ($symmetric !== []) {
            $symmetricEnds = self::symmetricEndsAscii($name, $length, $symmetric);
        }

        /** @var list<array{string, bool}> $closers open spans' closer byte + is-symmetric */
        $closers = [];
        /** @var list<string> $openQuotes symmetric delimiters currently open */
        $openQuotes = [];
        /** @var list<list<int>> $pendingCommas comma offsets per open span */
        $pendingCommas = [];
        /** @var array<int, true> $mask */
        $mask = [];
        $trackedCommas = 0;

        for ($i = 0; $i < $length;) {
            $depth = count($closers);
            $byte = $name[$i];

            if ($depth > 0) {
                [$closeByte, $isSymmetric] = $closers[$depth - 1];

                if ($byte === $closeByte
                    && (! $isSymmetric
                        || self::isTokenBoundary($i + 1 < $length ? $name[$i + 1] : null))) {
                    array_pop($closers);
                    if ($isSymmetric) {
                        array_pop($openQuotes);
                    }
                    foreach (array_pop($pendingCommas) ?? [] as $pos) {
                        $mask[$pos] = true;
                    }

                    ++$i;

                    continue;
                }
            }

            $canOpen = $depth < self::MAX_MASK_NESTING_DEPTH
                && $trackedCommas < self::MAX_MASKED_COMMAS;

            if ($canOpen && isset($asciiPairs[$byte])) {
                $closers[] = [$asciiPairs[$byte], false];
                $pendingCommas[] = [];
                ++$i;

                continue;
            }

            if ($canOpen
                && isset($symmetric[$byte])
                && self::isTokenBoundary($i > 0 ? $name[$i - 1] : null)) {
                $hasCloser = false;
                foreach ($symmetricEnds[$byte] ?? [] as $end) {
                    if ($end >= $i + 1) {
                        $hasCloser = true;

                        break;
                    }
                }

                if ($hasCloser && ! in_array($byte, $openQuotes, true)) {
                    $openQuotes[] = $byte;
                    $closers[] = [$byte, true];
                    $pendingCommas[] = [];
                    ++$i;

                    continue;
                }
            }

            if ($byte === ',' && $depth > 0 && $trackedCommas < self::MAX_MASKED_COMMAS) {
                $pendingCommas[$depth - 1][] = $i;
                ++$trackedCommas;
            }

            ++$i;
        }

        if ($mask === []) {
            return $name;
        }

        foreach (array_keys($mask) as $pos) {
            $name[$pos] = self::COMMA_PLACEHOLDER;
        }

        return $name;
    }

    /**
     * Index token-final quotes, excluding self-balanced tokens that cannot
     * close an earlier orphan opener.
     *
     * @param  array<string, true>  $symmetric
     * @return array<string, list<int>>
     */
    private static function symmetricEndsAscii(string $name, int $length, array $symmetric): array
    {
        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        $tokenStart = null;
        for ($i = 0; $i <= $length; ++$i) {
            $byte = $i < $length ? $name[$i] : null;

            if ($byte === null || $byte === ' ' || $byte === ',') {
                if ($tokenStart !== null) {
                    $end = $i;

                    foreach ($symmetric as $quote => $_) {
                        $quote = (string) $quote;
                        $closerStart = $end - 1;

                        if ($closerStart < $tokenStart || $name[$closerStart] !== $quote) {
                            continue;
                        }

                        // Self-balanced quoted tokens cannot close an earlier orphan quote.
                        if ($end - $tokenStart >= 2 && $name[$tokenStart] === $quote) {
                            continue;
                        }

                        $symmetricEnds[$quote][] = $closerStart;
                    }

                    $tokenStart = null;
                }
            } elseif ($tokenStart === null) {
                $tokenStart = $i;
            }
        }

        return $symmetricEnds;
    }

    /**
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private static function maskMultibyte(string $name, array $pairs, array $symmetric): string
    {
        $chars = mb_str_split($name, 1, 'UTF-8');
        $total = count($chars);

        // Longest opener wins, so << takes precedence over <.
        /** @var list<array{list<string>, string, bool}> $openers opener chars, closer string, is-symmetric */
        $openers = [];
        foreach ($pairs as $open => $close) {
            $openers[] = [mb_str_split((string) $open, 1, 'UTF-8'), $close, false];
        }
        foreach (array_keys($symmetric) as $quote) {
            $openers[] = [mb_str_split((string) $quote, 1, 'UTF-8'), (string) $quote, true];
        }
        usort($openers, static fn(array $a, array $b): int => count($b[0]) <=> count($a[0]));

        /** @var array<string, list<array{list<string>, string, bool}>> $openersByFirst */
        $openersByFirst = [];
        foreach ($openers as $opener) {
            $openersByFirst[$opener[0][0]][] = $opener;
        }

        // Precompute closer offsets to avoid rescanning for every opener.
        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        if ($symmetric !== []) {
            $symmetricEnds = self::symmetricEndsChars($chars, $total, $symmetric);
        }

        /** @var list<array{list<string>, bool}> $closers open spans' closer chars + is-symmetric */
        $closers = [];
        /** @var list<string> $openQuotes symmetric delimiters currently open */
        $openQuotes = [];
        /** @var list<list<int>> $pendingCommas comma offsets per open span */
        $pendingCommas = [];
        /** @var array<int, true> $mask */
        $mask = [];

        for ($i = 0; $i < $total;) {
            $depth = count($closers);

            if ($depth > 0) {
                [$closerChars, $isSymmetric] = $closers[$depth - 1];
                $closerLen = count($closerChars);

                if (self::charsMatchAt($chars, $i, $closerChars)
                    && (! $isSymmetric
                        || self::isTokenBoundary($chars[$i + $closerLen] ?? null))) {
                    array_pop($closers);
                    if ($isSymmetric) {
                        array_pop($openQuotes);
                    }
                    foreach (array_pop($pendingCommas) ?? [] as $pos) {
                        $mask[$pos] = true;
                    }

                    $i += $closerLen;

                    continue;
                }
            }

            foreach ($openersByFirst[$chars[$i]] ?? [] as [$openChars, $close, $isSymmetric]) {
                if (
                    $isSymmetric
                    && ! self::isTokenBoundary($chars[$i - 1] ?? null)
                ) {
                    continue;
                }

                if (! self::charsMatchAt($chars, $i, $openChars)) {
                    continue;
                }

                $openLen = count($openChars);

                if ($isSymmetric) {
                    $hasCloser = false;
                    foreach ($symmetricEnds[$close] ?? [] as $end) {
                        if ($end >= $i + $openLen) {
                            $hasCloser = true;

                            break;
                        }
                    }

                    if (! $hasCloser || in_array($close, $openQuotes, true)) {
                        continue;
                    }

                    $openQuotes[] = $close;
                }

                $closers[] = [mb_str_split($close, 1, 'UTF-8'), $isSymmetric];
                $pendingCommas[] = [];
                $i += $openLen;

                continue 2;
            }

            if ($chars[$i] === ',' && $depth > 0) {
                $pendingCommas[$depth - 1][] = $i;
            }

            $i++;
        }

        if ($mask === []) {
            return $name;
        }

        foreach (array_keys($mask) as $pos) {
            $chars[$pos] = self::COMMA_PLACEHOLDER;
        }

        return implode('', $chars);
    }

    /**
     * @param  list<string>  $chars
     * @param  array<string, true>  $symmetric
     * @return array<string, list<int>>
     */
    private static function symmetricEndsChars(array $chars, int $total, array $symmetric): array
    {
        /** @var list<array{int, int}> $tokenRanges token start, end (exclusive) */
        $tokenRanges = [];
        $tokenStart = null;
        for ($i = 0; $i <= $total; ++$i) {
            if (self::isTokenBoundary($chars[$i] ?? null)) {
                if ($tokenStart !== null) {
                    $tokenRanges[] = [$tokenStart, $i];
                    $tokenStart = null;
                }
            } elseif ($tokenStart === null) {
                $tokenStart = $i;
            }
        }

        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        foreach (array_keys($symmetric) as $quote) {
            $quote = (string) $quote;
            $quoteChars = mb_str_split($quote, 1, 'UTF-8');
            $len = count($quoteChars);

            foreach ($tokenRanges as [$start, $end]) {
                $closerStart = $end - $len;
                if ($closerStart < $start || ! self::charsMatchAt($chars, $closerStart, $quoteChars)) {
                    continue;
                }

                // Self-balanced quoted tokens cannot close an earlier orphan quote.
                if ($end - $start >= $len * 2 && self::charsMatchAt($chars, $start, $quoteChars)) {
                    continue;
                }

                $symmetricEnds[$quote][] = $closerStart;
            }
        }

        return $symmetricEnds;
    }

    /**
     * @param  list<string>  $chars
     * @param  list<string>  $needle
     */
    private static function charsMatchAt(array $chars, int $offset, array $needle): bool
    {
        foreach ($needle as $j => $needleChar) {
            if (($chars[$offset + $j] ?? null) !== $needleChar) {
                return false;
            }
        }

        return true;
    }

    private static function isTokenBoundary(?string $char): bool
    {
        return $char === null || $char === ' ' || $char === ',';
    }

    /**
     * @see Parser
     */
    private function __construct() {}
}
