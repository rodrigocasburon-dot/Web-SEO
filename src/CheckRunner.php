<?php
/** Ejecuta todas las comprobaciones disponibles sobre un contexto. */
class CheckRunner
{
    /** @return BaseCheck[] */
    public static function checks(): array
    {
        return [
            new C01HttpsStatus(),
            new C02BotsIndexing(),
            new C03RawContent(),
            new C04AccessibleNames(),
            new C05Headings(),
            new C06ImageAlt(),
            new C07Language(),
            new C09Viewport(),
            new C10Title(),
            new C11Semantics(),
            new C12Canonical(),
            new C13StructuredData(),
            new C15MetaDescription(),
        ];
    }

    /** @return array[] registros de las comprobaciones, en orden */
    public function run(CheckContext $ctx): array
    {
        $out = [];
        foreach (self::checks() as $check) {
            try {
                $out[] = $check->run($ctx);
            } catch (Throwable $e) {
                // Un error interno no debe tumbar el análisis ni penalizar: se muestra como sin datos.
                $out[] = [
                    'id' => $check->id(),
                    'nombre' => Lang::check($check->id(), 'nombre'),
                    'estado' => BaseCheck::NO_DATA,
                    'detalle' => 'Error interno al evaluar: ' . $e->getMessage(),
                    'evidencia' => [],
                    'ocurrencias' => 0,
                    'aplica' => true,
                ];
            }
        }
        return $out;
    }
}
