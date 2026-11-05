<?php
/**
 * C11 HTML semántico:
 * - roles ARIA inexistentes (ninguno de los tokens es un rol válido) → NO_CUMPLE
 * - sin main (ni role="main") → AVISO
 * - onclick en elementos que no son controles nativos (a, button, input, select, textarea, summary, area) → AVISO
 */
class C11Semantics extends BaseCheck
{
    private const NATIVE_INTERACTIVE = ['a', 'button', 'input', 'select', 'textarea', 'summary', 'area', 'option'];

    // WAI-ARIA 1.2 (roles concretos), DPUB-ARIA 1.0 y Graphics ARIA
    private const ROLES = ['alert', 'alertdialog', 'application', 'article', 'banner', 'blockquote', 'button', 'caption',
        'cell', 'checkbox', 'code', 'columnheader', 'combobox', 'complementary', 'contentinfo', 'definition', 'deletion',
        'dialog', 'directory', 'document', 'emphasis', 'feed', 'figure', 'form', 'generic', 'grid', 'gridcell', 'group',
        'heading', 'img', 'insertion', 'link', 'list', 'listbox', 'listitem', 'log', 'main', 'marquee', 'math', 'menu',
        'menubar', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'meter', 'navigation', 'none', 'note', 'option',
        'paragraph', 'presentation', 'progressbar', 'radio', 'radiogroup', 'region', 'row', 'rowgroup', 'rowheader',
        'scrollbar', 'search', 'searchbox', 'separator', 'slider', 'spinbutton', 'status', 'strong', 'subscript',
        'superscript', 'switch', 'tab', 'table', 'tablist', 'tabpanel', 'term', 'textbox', 'time', 'timer', 'toolbar',
        'tooltip', 'tree', 'treegrid', 'treeitem',
        'doc-abstract', 'doc-acknowledgments', 'doc-afterword', 'doc-appendix', 'doc-backlink', 'doc-biblioentry',
        'doc-bibliography', 'doc-biblioref', 'doc-chapter', 'doc-colophon', 'doc-conclusion', 'doc-cover', 'doc-credit',
        'doc-credits', 'doc-dedication', 'doc-endnote', 'doc-endnotes', 'doc-epigraph', 'doc-epilogue', 'doc-errata',
        'doc-example', 'doc-footnote', 'doc-foreword', 'doc-glossary', 'doc-glossref', 'doc-index', 'doc-introduction',
        'doc-noteref', 'doc-notice', 'doc-pagebreak', 'doc-pagelist', 'doc-part', 'doc-preface', 'doc-prologue',
        'doc-pullquote', 'doc-qna', 'doc-subtitle', 'doc-tip', 'doc-toc',
        'graphics-document', 'graphics-object', 'graphics-symbol'];

    protected string $id = 'C11';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.3.1 (Información y relaciones) y SC 4.1.2 (Nombre, función, valor); WAI-ARIA 1.2.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'MEDIO';

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();

        $invalid = [];
        foreach ($x->query('//*[@role]') as $el) {
            $tokens = preg_split('/\s+/', strtolower(trim($el->getAttribute('role'))), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens && !array_intersect($tokens, self::ROLES)) {
                $invalid[] = $el;
            }
        }
        $mains = $x->query('//main | //*[@role="main"]')->length;
        $onclick = [];
        foreach ($x->query('//*[@onclick]') as $el) {
            if (!in_array(strtolower($el->tagName), self::NATIVE_INTERACTIVE, true)) {
                $onclick[] = $el;
            }
        }

        $valor = ['roles_invalidos' => count($invalid), 'main' => $mains, 'onclick_no_interactivos' => count($onclick)];
        $umbral = 'Roles ARIA válidos, un main y onclick solo en controles nativos';

        $ev = [];
        foreach ($invalid as $el) {
            $e = DomHelper::evidence($el);
            $e['extracto'] = 'Rol no válido «' . $el->getAttribute('role') . '»: ' . $e['extracto'];
            $ev[] = $e;
        }
        if ($mains === 0) {
            $ev[] = DomHelper::note('(no hay elemento main ni role="main")', 'main');
        }
        foreach ($onclick as $el) {
            $ev[] = DomHelper::evidence($el);
        }

        $warnings = [];
        if ($mains === 0) {
            $warnings[] = 'no hay elemento main';
        }
        if ($onclick) {
            $warnings[] = count($onclick) . ' elemento(s) no interactivos con onclick';
        }

        if ($invalid) {
            $extra = $warnings ? ' Además: ' . implode('; ', $warnings) . '.' : '';
            return $this->result(self::FAIL, $valor, $umbral, $ev, count($invalid) . ' elemento(s) con roles ARIA inexistentes.' . $extra, count($invalid));
        }
        if ($warnings) {
            return $this->result(self::WARN, $valor, $umbral, $ev, ucfirst(implode('; ', $warnings)) . '.');
        }
        return $this->result(self::OK, $valor, $umbral, [], 'Sin errores detectables automáticamente: main presente, roles válidos y sin onclick en elementos no interactivos.');
    }
}
