<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Filament\Contracts\ComponentForm;

final class ComponentFormRegistry
{
    /** @var array<string, ComponentForm> */
    private array $forms = [];

    public function __construct(private Container $container, private ComponentRegistry $components) {}

    /** @param class-string<ComponentForm>|ComponentForm $form */
    public function register(string|ComponentForm $form): self
    {
        if (is_string($form)) {
            if (! is_subclass_of($form, ComponentForm::class)) {
                throw new InvalidArgumentException('Forms must implement ComponentForm.');
            }
            $form = $this->container->make($form);
        }
        if ($this->components->resolve($form->type()) === null) {
            throw new InvalidArgumentException('Register the core definition before its form.');
        }
        if (isset($this->forms[$form->type()])) {
            throw new InvalidArgumentException('A form is already registered for this component.');
        }
        $this->forms[$form->type()] = $form;

        return $this;
    }

    public function resolve(string $type): ?ComponentForm
    {
        if (isset($this->forms[$type])) {
            return $this->forms[$type];
        }
        $definition = $this->components->resolve($type);

        return $definition !== null && StandardComponentForm::supports($type) ? new StandardComponentForm($definition) : null;
    }

    /** @return array<string, ComponentForm> */
    public function all(): array
    {
        $forms = $this->forms;
        foreach ($this->components->all() as $type => $definition) {
            if (! isset($forms[$type]) && StandardComponentForm::supports($type)) {
                $forms[$type] = new StandardComponentForm($definition);
            }
        }

        return $forms;
    }
}
