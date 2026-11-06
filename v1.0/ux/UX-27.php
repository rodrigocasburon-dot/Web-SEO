<?php
// UX-27 Rechazar cookies: el aviso ofrece rechazar en el mismo nivel que aceptar.
return function (array $p): array {
    $minusculas = "translate(concat(@id, ' ', @class), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
    $zona = "//*[contains($minusculas, 'cookie') or contains($minusculas, 'consent') or contains($minusculas, 'cmplz') or contains($minusculas, 'gdpr')]";
    $textos = [];
    foreach ($p['xpath']->query("$zona/descendant-or-self::*[self::button or self::a or @role=\"button\" or self::input[@type=\"button\" or @type=\"submit\"]]") as $control) {
        $textos[] = mb_strtolower(trim($control->textContent . ' ' . $control->getAttribute('value') . ' ' . $control->getAttribute('aria-label')));
    }
    $todo = implode(' | ', $textos);
    $acepta = preg_match('/acept|accept|allow|permitir|de acuerdo|entendido/u', $todo);
    $rechaza = preg_match('/rechaz|reject|deneg|decline|deny|no acepto|solo (las )?(necesarias|esenciales|imprescindibles|técnicas)|only (necessary|essential)/u', $todo);
    $configura = preg_match('/configur|preferenc|ajustes|personaliz|settings|manage|gestionar/u', $todo);

    if ($acepta && $rechaza) {
        return cumple('El aviso de cookies tiene un botón para aceptar y otro para rechazar.');
    }
    if ($acepta) {
        return aviso($configura
            ? 'Aceptar es un botón directo, pero para rechazar hay que entrar en la configuración.'
            : 'El aviso de cookies permite aceptar y no se detecta cómo rechazar.');
    }
    if (preg_match('/(seguir|continuar) navegando|si continúa|if you continue/iu', $p['texto'])
        && stripos($p['texto'], 'cookie') !== false) {
        return aviso('El aviso da por aceptadas las cookies al seguir navegando.');
    }
    return preg_match('/cookiebot|onetrust|cookieyes|complianz|didomi|iubenda|usercentrics|cookie-?law|cookie-?notice|cookie-?consent|borlabs|cmplz/i', $p['html'])
        ? sin_datos('El aviso de cookies se crea con JavaScript y sus botones no están en el HTML.')
        : no_aplica('No se detecta aviso de cookies en el HTML.');
};
