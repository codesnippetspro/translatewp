import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';

const formHtml = `
    <form>
      <label for="name">Your Name</label>
      <input id="name" type="text" placeholder="Enter your name" value="John" aria-label="Name field">
      <textarea placeholder="Your message"></textarea>
      <button type="submit">Send Message</button>
      <input type="submit" value="Submit Form">
      <input type="button" value="Cancel">
      <input type="reset" value="Reset Form">
      <select><option>Choice A</option></select>
    </form>
`;

describe('form field extraction', () =>
{
    it('extracts placeholders, aria-labels, and button values when attributes are included', () =>
    {
        document.body.innerHTML = formHtml;

        const values = new DOMExtractor()
            .extract(document.body, { includeAlt: true, includeAttributes: true })
            .map(entry => entry.value.trim());

        expect(values).toEqual(expect.arrayContaining([
            'Your Name',
            'Enter your name',
            'Your message',
            'Name field',
            'Send Message',
            'Submit Form',
            'Cancel',
            'Reset Form',
            'Choice A',
        ]));
        expect(values).not.toContain('John');
    });
});
