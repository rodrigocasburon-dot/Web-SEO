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
            // Fase 8: comprobaciones adicionales (C16 y C18 descargan recursos con $ctx->fetch)
            new C16BrokenLinks(),
            new C18Sitemap(),
            new C20HttpsSecurity(),
        ];
    }

    /** Comprobaciones que dependen de PageSpeed Insights (se ejecutan en api/psi.php). */
    public static function psiChecks(): array
    {
        return [new C08Contrast(), new C14CoreWebVitals()];
    }

    /** @return array<string, BaseCheck> todas las comprobaciones (HTML y PageSpeed) indexadas por id */
    public static function catalog(): array
    {
        $out = [];
        foreach (array_merge(self::checks(), self::psiChecks()) as $check) {
            $out[$check->id()] = $check;
        }
        return $out;
    }

    /** @return array[] registros de las comprobaciones, en orden */
    public function run(CheckContext $ctx): array
    {
        return $this->runAll(self::checks(), $ctx);
    }

    /** @return array[] registros de C08 y C14 */
    public function runPsi(CheckContext $ctx): array
    {
        return $this->runAll(self::psiChecks(), $ctx);
    }

    /** @param BaseCheck[] $checks */
    private function runAll(array $checks, CheckContext $ctx): array
    {
        $out = [];
        foreach ($checks as $check) {
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
