<?php

namespace TranslateWP\Entities\Scripts;

use TranslateWP\Entities\Script;

class Editor extends Script {

	protected string $type = 'editor';
	protected string $name = 'editor';

	public function conditions(): bool {
		return true;
	}
}
