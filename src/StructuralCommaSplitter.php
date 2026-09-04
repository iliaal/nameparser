<?php

namespace Iliaal\NameParser;

/**
 * structural comma handling for Parser (np-cr-026): splitting a normalized
 * name on commas that are not shielded inside a nickname span, and masking
 * those shielded commas with a placeholder first. Pure string work over the
 * caller's nickname delimiters; Parser stays the thin router that supplies
 * its configured delimiters.
 */
final class StructuralCommaSplitter
{
    public const string COMMA_PLACEHOLDER = "\x00";

    /**
     * bounds for the byte-level mask scan (np-cr-017): nesting depth and total
     * masked commas are capped so a hostile row (megabytes of openers/commas)
     * costs bounded time and memory. Past the caps further openers read as
     * literals and further commas as structural. Only reachable on non-name
     * input: the token budget rejects such rows at parse() top, and real
     * nickname spans nest one or two deep with a handful of commas.
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

        // masking only swaps ',' <-> a same-width placeholder, so byte offsets
        // in the masked string map directly back onto the original
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

        // char/byte-mix guard (np-o-02): the char scan below substitutes
        // invalid sequences (changing byte length) while the split slices byte
        // offsets, so invalid UTF-8 plus an opener byte could shield/expose the
        // wrong comma. Bail to unmasked (deterministic split); parse() scrubs
        // invalid input up front, so this only fires for raw-byte callers.
        if (! mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }

        [$pairs, $symmetric] = self::splitDelimiters(
            Text::sanitizeNicknameDelimiters($nicknameDelimiters),
        );

        if ($pairs === [] && $symmetric === []) {
            return $name;
        }

        // byte-level pre-check: no opener byte present means nothing to mask,
        // skipping the scan on the common bracket-free row
        $openerBytes = implode('', array_merge(array_keys($pairs), array_keys($symmetric)));
        if (strpbrk($name, $openerBytes) === false) {
            return $name;
        }

        // single-byte delimiters scan bytewise (np-cr-011, np-cr-017):
        // identical results to the char scan on valid UTF-8 (an ASCII byte
        // never appears inside a multibyte sequence), with no per-character
        // array, so no length cap is needed and long rows keep their shielding
        // within bounded cost (nesting-depth + masked-comma caps, documented
        // on the byte scanner).
        if (self::allSingleByteDelimiters($pairs, $symmetric)) {
            return self::maskAscii($name, $pairs, $symmetric);
        }

        // multibyte delimiters keep the char scan below; hostile megabyte rows
        // would materialize a per-character array, so past this size commas
        // split unshielded (documented real-names-are-tiny tradeoff).
        if (strlen($name) > 4096) {
            return $name;
        }

        return self::maskMultibyte($name, $pairs, $symmetric);
    }

    /**
     * partition sanitized delimiters into asymmetric pairs and symmetric quotes.
     *
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
     * whether every delimiter is one byte (on valid UTF-8 input, one byte is
     * ASCII, which never appears inside a multibyte sequence)
     *
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
     * byte-level twin of the char scan in maskMultibyte(), used when all
     * delimiters are single-byte: byte offsets are char offsets on valid UTF-8
     * input, so the results are identical with no per-character array and no
     * length cap (np-cr-011, np-cr-017). Mirrors the char scan rule for rule,
     * including symmetric open-at-token-start / close-at-token-end.
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

        // token-end byte offsets per symmetric quote (closer length is 1), so
        // each opener's closer lookahead is a bounded list walk
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
     * token-end byte offsets per symmetric quote for the byte scan: each token
     * ending in a quote char records that offset, unless the token is
     * self-balanced (opens with the same quote), so a leading elided particle
     * ("'t") never serves as the closer for an earlier orphan opener.
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

                        // a self-balanced quoted token ("'Genius'") closes
                        // itself; its tail quote must not serve as the
                        // closer for an earlier orphan opener
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
     * char-level mask scan for multibyte delimiters.
     *
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private static function maskMultibyte(string $name, array $pairs, array $symmetric): string
    {
        $chars = mb_str_split($name, 1, 'UTF-8');
        $total = count($chars);

        // pre-split every delimiter once; openers sorted longest-first so a
        // multi-character delimiter ("<<") wins over a single-char prefix ("<")
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

        // token-end offsets per symmetric delimiter, so each opener's closer
        // lookahead is a bounded list walk instead of a rescan. Skipped when
        // no symmetric delimiter exists (np-cr-011): the common asymmetric
        // row avoids the token-range materialization entirely.
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
     * token-end char offsets per symmetric quote for the char scan: the twin
     * of symmetricEndsAscii() over characters instead of bytes.
     *
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

                // a self-balanced quoted token ("'Genius'") closes itself; its
                // tail quote must not serve as the closer for an earlier orphan
                // opener, or a leading elided particle ("'t") would open a span
                // that swallows the structural comma
                if ($end - $start >= $len * 2 && self::charsMatchAt($chars, $start, $quoteChars)) {
                    continue;
                }

                $symmetricEnds[$quote][] = $closerStart;
            }
        }

        return $symmetricEnds;
    }

    /**
     * whether the character sequence at $offset equals $needle
     *
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
     * retained for subclasses that reach the splitter through Parser: all
     * entry points are static, and Parser keeps thin private delegates.
     *
     * @see Parser
     */
    private function __construct() {}
}
