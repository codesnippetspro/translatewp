import { editMode, enterEditModeFromUrl, EDIT_MODE_PARAM } from '../editMode';

// Settings > Switcher links to the site with ?twp-edit=1 so an administrator
// lands on the page already in edit mode.
describe('the edit mode link from Settings', () =>
{
    beforeEach(() =>
    {
        editMode.set(false);
        window.history.replaceState(null, '', '/');
    });

    it('uses the twp-edit query parameter', () =>
    {
        expect(EDIT_MODE_PARAM).toBe('twp-edit');
    });

    it('a manager arriving with ?twp-edit=1 starts in edit mode and the parameter is removed', () =>
    {
        window.history.replaceState(null, '', '/about/?twp-edit=1&keep=yes#top');
        enterEditModeFromUrl(true);

        expect(editMode.get()).toBe(true);
        expect(window.location.search).toBe('?keep=yes');
        expect(window.location.pathname).toBe('/about/');
        expect(window.location.hash).toBe('#top');
    });

    it('a visitor with the same link never enters edit mode', () =>
    {
        window.history.replaceState(null, '', '/?twp-edit=1');
        enterEditModeFromUrl(false);

        expect(editMode.get()).toBe(false);
    });

    it('a manager without the parameter stays out of edit mode', () =>
    {
        enterEditModeFromUrl(true);

        expect(editMode.get()).toBe(false);
    });
});
