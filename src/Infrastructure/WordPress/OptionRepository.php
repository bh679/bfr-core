<?php
declare(strict_types=1);

namespace BFR\Infrastructure\WordPress;

final class OptionRepository
{
    private string $registry_option_key = 'bfr_calculator_configs';

    /**
     * @return array<string, array<string,mixed>>
     */
    public function get_registry_overrides(): array
    {
        $val = get_option($this->registry_option_key, []);
        return is_array($val) ? $val : [];
    }

    /**
     * @param array<string, array<string,mixed>> $overrides
     */
    public function save_registry_overrides(array $overrides): void
    {
        update_option($this->registry_option_key, $overrides, false);
    }
}
