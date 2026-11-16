const PRINTED_PHP_DIAGNOSTIC = /^\s*(?:PHP\s+)?(?:Deprecated|Warning|Notice|Fatal error|Parse error|Strict Standards|Recoverable fatal error):[^\r\n]*\s+in\s+(?:\/|[A-Z]:[\\/])[^\r\n]+\s+on line\s+\d+[.!]?\s*$/i;
const SEVERITY_LABEL = /^(?:PHP\s+)?(?:Deprecated|Warning|Notice|Fatal error|Parse error|Strict Standards|Recoverable fatal error)$/i;

export const isPrintedPhpDiagnostic = (text: string): boolean => PRINTED_PHP_DIAGNOSTIC.test(text);

export const createPrintedPhpDiagnosticFilter = (): ((node: Node) => boolean) =>
{
    const parents = new WeakMap<Node, Set<Node>>();
    const markedChildren = (parent: Node): Set<Node> =>
    {
        const marked = new Set<Node>();
        let run: Node[] | null = null;
        const finish = () =>
        {
            if (run && isPrintedPhpDiagnostic(run.map(node => node.textContent ?? '').join('').replace(/\s+/g, ' ')))
                for (const node of run) marked.add(node);
            run = null;
        };
        for (const child of Array.from(parent.childNodes))
        {
            const tag = child.nodeType === 1 ? (child as Element).tagName : '';
            if (tag === 'BR') { finish(); continue; }
            if ((tag === 'B' || tag === 'STRONG') && SEVERITY_LABEL.test(child.textContent?.trim() ?? '')
                && child.nextSibling?.nodeType === 3 && /^\s*:/.test(child.nextSibling.textContent ?? ''))
            {
                finish();
                run = [];
            }
            run?.push(child);
        }
        finish();
        return marked;
    };
    return (node: Node): boolean =>
    {
        for (let child: Node | null = node; child?.parentNode; child = child.parentNode)
        {
            const parent = child.parentNode;
            let marked = parents.get(parent);
            if (!marked) { marked = markedChildren(parent); parents.set(parent, marked); }
            if (marked.has(child)) return true;
        }
        return false;
    };
};
