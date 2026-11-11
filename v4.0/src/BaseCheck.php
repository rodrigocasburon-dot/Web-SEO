<?php
/**
 * Base de las comprobaciones. Cada comprobación es una clase en src/Checks/ que implementa
 * evaluate() y devuelve el registro mediante result().
 *
 * Estados: CUMPLE, NO_CUMPLE, AVISO, SIN_DATOS. Solo NO_CUMPLE penaliza (lo decide el Scorer).
 * Severidad: CRITICO, ALTO, MEDIO, BAJO. Impacto y esfuerzo: ALTO, MEDIO, BAJO (borrador).
 * Los textos de recomendación vienen de lang/es.php.
 */
abstract class BaseCheck
{
    public const OK = 'CUMPLE';
    public const FAIL = 'NO_CUMPLE';
    public const WARN = 'AVISO';
    public const NO_DATA = 'SIN_DATOS';

    protected string $id = '';
    protected string $severity = 'MEDIO';
    protected string $criterio = '';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'MEDIO';
    protected bool $manual = false;
    /** Si es true, sin HTML fiable (bloqueo, no HTML) devuelve SIN_DATOS sin evaluar. */
    protected bool $needsHtml = true;

    private static ?array $weights = null;

    public function id(): string
    {
        return $this->id;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    /**
     * Registro con los datos fijos de la comprobación (nombre, reparto, gravedad, recomendación según
     * el estado, impacto, esfuerzo) y sin nada medido. Lo usa api/score.php para reconstruir puntuación
     * e informe a partir de id, estado y ocurrencias sin fiarse del resto de lo que envía el cliente.
     */
    public function describe(string $estado, int $ocurrencias = 0, bool $aplica = true): array
    {
        return $this->result($estado, null, null, [], '', $ocurrencias, $aplica);
    }

    final public function run(CheckContext $ctx): array
    {
        if ($this->needsHtml) {
            if ($ctx->blocked) {
                return $this->result(self::NO_DATA, null, null, [], 'El sitio bloquea el acceso automático o exige acceso: el contenido recibido no es el de la página real.');
            }
            if (!$ctx->isHtml()) {
                return $this->result(self::NO_DATA, $ctx->contentType, null, [], 'El recurso no es HTML; no se puede evaluar.');
            }
        }
        $r = $this->evaluate($ctx);
        if ($this->needsHtml && $ctx->truncated) {
            $r['detalle'] .= ' Aviso: el HTML se truncó al llegar al límite de 2 MB; el análisis es parcial.';
        }
        return $r;
    }

    abstract protected function evaluate(CheckContext $ctx): array;

    /**
     * Construye el registro de la comprobación.
     *
     * @param array $evidencia lista de ['selector'=>?string, 'extracto'=>string, 'linea'=>?int] (máx. 10)
     * @param int   $ocurrencias nº de veces que aparece el fallo (k de la puntuación)
     * @param bool  $aplica false cuando no hay elementos que evaluar (no se muestra como fallo ni como dato ausente)
     */
    protected function result(
        string $estado,
        $valor,
        $umbral,
        array $evidencia,
        string $detalle,
        int $ocurrencias = 0,
        bool $aplica = true
    ): array {
        $recomendacion = '';
        if ($estado === self::FAIL || $estado === self::WARN) {
            $recomendacion = $estado === self::WARN && Lang::check($this->id, 'recomendacion_aviso') !== ''
                ? Lang::check($this->id, 'recomendacion_aviso')
                : Lang::check($this->id, 'recomendacion');
        }
        $row = self::weights()[$this->id] ?? [0, 0, 0];
        return [
            'id' => $this->id,
            'nombre' => Lang::check($this->id, 'nombre'),
            'pilares' => ['seo' => $row[0], 'ia' => $row[1], 'accesibilidad' => $row[2]],
            'estado' => $estado,
            'severidad' => $this->severity,
            'valor_medido' => $valor,
            'umbral' => $umbral,
            'criterio' => $this->criterio,
            'evidencia' => array_slice($evidencia, 0, 10),
            'recomendacion' => $recomendacion,
            'impacto' => $this->impacto,
            'esfuerzo' => $this->esfuerzo,
            'requiere_revision_manual' => $this->manual,
            // Campos adicionales: k para la puntuación, "no aplica" y explicación de lo medido
            'ocurrencias' => $estado === self::FAIL ? max(1, $ocurrencias) : $ocurrencias,
            'aplica' => $aplica,
            'detalle' => $detalle,
        ];
    }

    private static function weights(): array
    {
        if (self::$weights === null) {
            self::$weights = (array) require __DIR__ . '/../config/weights.php';
        }
        return self::$weights;
    }
}
