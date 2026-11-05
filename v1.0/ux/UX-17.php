<?php
// UX-17 Roles ARIA válidos: los valores de role existen en la especificación.
return function (array $p): array {
    $validos = ['alert','alertdialog','application','article','banner','blockquote','button','caption','cell','checkbox','code','columnheader','combobox','complementary','contentinfo','definition','deletion','dialog','directory','document','emphasis','feed','figure','form','generic','grid','gridcell','group','heading','img','image','insertion','link','list','listbox','listitem','log','main','mark','marquee','math','menu','menubar','menuitem','menuitemcheckbox','menuitemradio','meter','navigation','none','note','option','paragraph','presentation','progressbar','radio','radiogroup','region','row','rowgroup','rowheader','scrollbar','search','searchbox','separator','slider','spinbutton','status','strong','subscript','suggestion','superscript','switch','tab','table','tablist','tabpanel','term','textbox','time','timer','toolbar','tooltip','tree','treegrid','treeitem'];
    $roles = $p['xpath']->query('//*[@role]/@role');
    if ($roles->length === 0) {
        return no_aplica('La página no usa roles ARIA.');
    }
    $malos = [];
    foreach ($roles as $rol) {
        foreach (preg_split('/\s+/', strtolower(trim($rol->value))) as $valor) {
            if ($valor !== '' && !in_array($valor, $validos, true)) {
                $malos[] = $valor;
            }
        }
    }
    return $malos
        ? no_cumple('Roles que no existen: ' . implode(', ', array_slice(array_unique($malos), 0, 5)) . '.')
        : cumple("Los {$roles->length} roles ARIA son válidos.");
};
