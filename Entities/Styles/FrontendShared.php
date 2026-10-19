<?php

namespace TranslateWP\Entities\Styles;

use TranslateWP\Entities\Scripts\TranslateWP;
use TranslateWP\Entities\Style;

class FrontendShared extends Style {

    protected string $type = 'frontend';
    protected string $name = 'shared';
    protected array $dependencies = [];

    public function conditions(): bool {
        return TranslateWP::loads_on_front_end();
    }
}
