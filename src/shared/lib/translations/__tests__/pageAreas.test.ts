import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { PAGE_AREAS, pageAreaSelector } from '@/shared/lib/translations/pageAreas';

const page = `
<header><nav class="main-navigation"><a href="/">Home</a><a href="/about">About us</a></nav></header>
<main><article><h1>Welcome</h1><p>Body copy</p></article>
<section id="comments" class="comments-area"><ol class="comment-list"><li class="comment"><p class="comment-content">Great post</p></li></ol>
<div id="respond"><label>Your comment</label></div></section></main>
<aside class="widget-area"><div class="widget"><h2>Recent posts</h2><img alt="Widget image"></div></aside>`;

const texts = (areas: string[]) =>
    new DOMExtractor().extract(document.body, { includeAlt: true, includeAttributes: false, excludedAreas: areas })
        .map(entry => entry.value.trim());

describe('page areas left untranslated', () =>
{
    beforeEach(() => { document.body.innerHTML = page; });

    it('knows three areas, each with a selector', () =>
    {
        expect(PAGE_AREAS).toEqual(['comments', 'menus', 'widgets']);
        expect(pageAreaSelector(['comments'])).toContain('.comment-list');
        expect(pageAreaSelector([])).toBe('');
        expect(pageAreaSelector(['unknown'])).toBe('');
    });

    it('extracts everything when no area is excluded', () =>
    {
        const all = texts([]);
        expect(all).toEqual(expect.arrayContaining(['Home', 'Welcome', 'Great post', 'Your comment', 'Recent posts', 'Widget image']));
    });

    it('skips comments, menus and widgets when they are excluded, and nothing else', () =>
    {
        const noComments = texts(['comments']);
        expect(noComments).not.toContain('Great post');
        expect(noComments).not.toContain('Your comment');
        expect(noComments).toContain('Home');
        expect(noComments).toContain('Welcome');

        const noMenus = texts(['menus']);
        expect(noMenus).not.toContain('Home');
        expect(noMenus).not.toContain('About us');
        expect(noMenus).toContain('Great post');

        const noWidgets = texts(['widgets']);
        expect(noWidgets).not.toContain('Recent posts');
        expect(noWidgets).not.toContain('Widget image');
        expect(noWidgets).toContain('Welcome');

        expect(texts(['comments', 'menus', 'widgets'])).toEqual(['Welcome', 'Body copy']);
    });
});
