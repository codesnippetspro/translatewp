import domReady from '@wordpress/dom-ready';

import { registerGutenbergSwitcher } from '@/apps/editor/gutenbergSwitcher';

domReady(registerGutenbergSwitcher);
