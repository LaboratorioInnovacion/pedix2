<?php declare(strict_types=1);
namespace VO\Delivery;

/**
 * Normalizes addresses and zone match terms for coverage resolution.
 * Rules (spec delivery D2): lowercase, trim, strip accents; terms split on comma/slash.
 */
final class ZoneMatcher
{
    private const ACCENTS = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u'];

    /** Lowercase, trim, and strip accents so text comparisons ignore casing/diacritics. */
    public static function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), self::ACCENTS);
    }

    /** Split a zone's match_terms on comma/slash into normalized, non-empty terms. */
    public static function terms(string $matchTerms): array
    {
        $raw = preg_split('/[\/,]/', mb_strtolower(trim($matchTerms))) ?: [];
        $out = [];
        foreach ($raw as $t) { $n = self::normalize($t); if ($n !== '') $out[] = $n; }
        return $out;
    }

    /** Substring containment of any term inside the normalized address haystack. */
    public static function matches(string $haystack, array $terms): bool
    {
        foreach ($terms as $t) { $t = self::normalize((string)$t); if ($t !== '' && str_contains($haystack, $t)) return true; }
        return false;
    }
}
