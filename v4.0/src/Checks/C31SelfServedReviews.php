<?php
/**
 * C31 Reseñas autoservidas (GEO-31; corrige el criterio de GEO-08). Google no muestra estrellas para
 * aggregateRating o review que una organización o un negocio publica sobre sí mismo (LocalBusiness u
 * Organization propios). Ese marcado no se premia:
 * - aggregateRating o review en el negocio propio → AVISO con el motivo. Nunca NO_CUMPLE.
 * - Sin ese marcado, o solo en negocios de otro dominio (directorios que recogen reseñas de otros) → no aplica.
 * Supuesto: el negocio es propio si su url o @id es del mismo dominio que la página o no tiene url.
 */
class C31SelfServedReviews extends BaseCheck
{
    protected string $id = 'C31';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Directrices de Google para fragmentos de reseñas: no se admiten las reseñas autoservidas de LocalBusiness u Organization. No es un criterio WCAG.';
    protected string $impacto = 'BAJO';
    protected string $esfuerzo = 'BAJO';
    protected string $respaldo = 'oficial';

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Sin aggregateRating ni review en el marcado de la propia organización o negocio';
        $host = self::base((string) parse_url($ctx->url, PHP_URL_HOST));
        $own = [];
        $others = 0;
        foreach (JsonLd::nodes($ctx->xpath()) as $node) {
            if (!JsonLd::isBusiness($node) || (!isset($node['aggregateRating']) && !isset($node['review']))) {
                continue;
            }
            $ref = is_string($node['url'] ?? null) ? $node['url'] : (is_string($node['@id'] ?? null) ? $node['@id'] : '');
            $refHost = self::base((string) parse_url($ref, PHP_URL_HOST));
            if ($refHost !== '' && $refHost !== $host) {
                $others++;
                continue;
            }
            $what = array_values(array_filter(['aggregateRating', 'review'], fn($k) => isset($node[$k])));
            $own[] = DomHelper::note(implode('/', (array) ($node['@type'] ?? '')) . ' «' . (is_string($node['name'] ?? null) ? $node['name'] : 'sin nombre') . '» con ' . implode(' y ', $what), 'script[type="application/ld+json"]');
        }
        $valor = ['propias' => count($own), 'de_otros_negocios' => $others];
        if ($own) {
            return $this->result(self::WARN, $valor, $umbral, $own,
                'El marcado de la propia organización o negocio incluye reseñas o valoraciones: Google no las admite para mostrar estrellas (reseñas autoservidas). Las reseñas que sí cuentan son las visibles en el texto.');
        }
        $detail = $others
            ? 'Las valoraciones del marcado son de otros negocios (' . $others . '): no aplica.'
            : 'No hay valoraciones en el marcado de la organización o el negocio: no aplica.';
        return $this->result(self::OK, $valor, $umbral, [], $detail, 0, false);
    }

    private static function base(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }
}
