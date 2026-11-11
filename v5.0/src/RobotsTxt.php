<?php
/**
 * Intérprete de robots.txt según RFC 9309: grupos por user-agent (sin distinguir mayúsculas),
 * comodines * y $, gana la regla más larga y, en empate, Allow.
 */
class RobotsTxt
{
    /** @var array<int, array{agents: string[], rules: array<int, array{0:string,1:string}>}> */
    private array $groups = [];

    public static function parse(string $text): self
    {
        $o = new self();
        $text = substr($text, 0, 512000);                 // límite habitual de 500 KiB
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !preg_match('/^([A-Za-z\-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            $value = trim($m[2]);
            if ($field === 'user-agent') {
                if ($current === null || !$lastWasAgent) {
                    $o->groups[] = ['agents' => [], 'rules' => []];
                    $current = count($o->groups) - 1;
                }
                $o->groups[$current]['agents'][] = self::token($value);
                $lastWasAgent = true;
            } elseif ($field === 'allow' || $field === 'disallow') {
                if ($current !== null) {
                    $o->groups[$current]['rules'][] = [$field, $value];
                }
                $lastWasAgent = false;
            }
            // Otros campos (sitemap, crawl-delay…) no afectan al acceso.
        }
        return $o;
    }

    /**
     * @param string $path ruta + consulta de la URL (p. ej. "/a/b?x=1")
     * @return array{allowed: bool, rule: ?string, group: ?string}
     */
    public function explain(string $agent, string $path): array
    {
        $agent = self::token($agent);
        $rules = [];
        $group = null;
        foreach ($this->groups as $g) {
            if (in_array($agent, $g['agents'], true)) {
                $rules = array_merge($rules, $g['rules']);
                $group = $agent;
            }
        }
        if ($group === null) {
            foreach ($this->groups as $g) {
                if (in_array('*', $g['agents'], true)) {
                    $rules = array_merge($rules, $g['rules']);
                    $group = '*';
                }
            }
        }
        if ($group === null) {
            return ['allowed' => true, 'rule' => null, 'group' => null];
        }

        $best = null;
        $bestLen = -1;
        foreach ($rules as [$type, $pattern]) {
            if ($pattern === '') {
                continue;                                  // "Disallow:" vacío no bloquea nada
            }
            if (self::matches($pattern, $path)) {
                $len = strlen($pattern);
                if ($len > $bestLen || ($len === $bestLen && $type === 'allow')) {
                    $best = [$type, $pattern];
                    $bestLen = $len;
                }
            }
        }
        return [
            'allowed' => $best === null || $best[0] === 'allow',
            'rule' => $best ? ucfirst($best[0]) . ': ' . $best[1] : null,
            'group' => $group,
        ];
    }

    /**
     * Token de producto en minúsculas (RFC 9309 2.2.1: letras, «_» y «-»). Se ignora el resto, como hace
     * Google: «GPTBot/1.0» y «googlebot*» equivalen a «gptbot» y «googlebot». «*» se conserva.
     */
    public static function token(string $ua): string
    {
        $ua = strtolower(trim($ua));
        if ($ua === '*') {
            return '*';
        }
        return preg_match('/^[a-z_\-]+/', $ua, $m) ? $m[0] : $ua;
    }

    /** ¿Tiene robots.txt un grupo propio para este bot (sin contar «*»)? */
    public function hasGroup(string $agent): bool
    {
        $agent = self::token($agent);
        foreach ($this->groups as $g) {
            if (in_array($agent, $g['agents'], true)) {
                return true;
            }
        }
        return false;
    }

    public function isAllowed(string $agent, string $path): bool
    {
        return $this->explain($agent, $path)['allowed'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $re = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
        return (bool) preg_match($re, $path);
    }
}
