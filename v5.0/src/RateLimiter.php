<?php
/**
 * Límite de uso por IP con ventana deslizante, guardado en ficheros de storage/.
 * Solo se guardan marcas de tiempo bajo un hash de la IP; nunca datos de los análisis.
 */
class RateLimiter
{
    private string $dir;
    private int $max;
    private int $window;
    /** @var callable */
    private $clock;

    public function __construct(string $dir, int $max, int $windowSeconds, ?callable $clock = null)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->max = max(1, $max);
        $this->window = max(1, $windowSeconds);
        $this->clock = $clock ?? 'time';
    }

    /**
     * Registra un intento y dice si está permitido.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function hit(string $key): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('No se puede crear el directorio del límite de uso.');
        }
        $now = (int) ($this->clock)();
        $file = $this->dir . '/rl_' . hash('sha256', $key) . '.json';

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            throw new RuntimeException('No se puede abrir el registro del límite de uso.');
        }
        try {
            flock($fh, LOCK_EX);
            $data = json_decode((string) stream_get_contents($fh), true);
            $hits = array_values(array_filter(is_array($data) ? $data : [], fn($t) => is_int($t) && $t > $now - $this->window));

            if (count($hits) >= $this->max) {
                $retry = max(1, min($hits) + $this->window - $now);
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retry];
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            return ['allowed' => true, 'remaining' => $this->max - count($hits), 'retry_after' => 0];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
            if (mt_rand(1, 50) === 1) {
                $this->cleanup($now);
            }
        }
    }

    /** Borra registros sin actividad dentro de la ventana. */
    public function cleanup(?int $now = null): void
    {
        $now = $now ?? (int) ($this->clock)();
        foreach (glob($this->dir . '/rl_*.json') ?: [] as $f) {
            if (@filemtime($f) < $now - $this->window) {
                @unlink($f);
            }
        }
    }
}
