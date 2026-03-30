<?php

declare(strict_types=1);

namespace WicketPortus\Contracts;

/**
 * Optional contract for modules that can expose concrete option groups
 * for the HyperFields admin export/import UI.
 */
interface OptionGroupProviderInterface
{
    /**
     * Returns option name => label pairs for UI rendering.
     *
     * @return array<string, string>
     */
    public function option_groups(): array;
}
