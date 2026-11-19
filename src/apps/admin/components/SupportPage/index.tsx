import { __ } from '@wordpress/i18n';

import { PageBody, PageHeader } from '@/apps/admin/components/PageLayout';
import { SupportPanel } from '@/apps/admin/components/Transparency/SupportPanel';

export const SupportPage = () => (
	<>
		<PageHeader title={__('Support', 'translate')} />
		<PageBody><SupportPanel /></PageBody>
	</>
);
