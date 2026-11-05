<?php
// GEO-09 Contacto directo: teléfono y dirección escritos en la página.
return function (array $p): array {
    $telefono = (bool) telefonos_en($p['texto']);
    $direccion = hay_direccion($p);
    if ($telefono && $direccion) {
        return cumple('El teléfono y la dirección están escritos en la página.');
    }
    return no_cumple($telefono ? 'Falta la dirección escrita en la página.' : ($direccion ? 'Falta el teléfono escrito en la página.' : 'Faltan el teléfono y la dirección escritos en la página.'));
};
