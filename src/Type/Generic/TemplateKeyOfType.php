<?php declare(strict_types = 1);

namespace PHPStan\Type\Generic;

use PHPStan\Type\KeyOfType;
use PHPStan\Type\Traits\UndecidedComparisonCompoundTypeTrait;
use PHPStan\Type\Type;

/** @api */
final class TemplateKeyOfType extends KeyOfType implements TemplateType
{

	/** @use TemplateTypeTrait<KeyOfType> */
	use TemplateTypeTrait;
	use UndecidedComparisonCompoundTypeTrait;

	public function __construct(
		TemplateTypeScope $scope,
		TemplateTypeStrategy $templateTypeStrategy,
		TemplateTypeVariance $templateTypeVariance,
		string $name,
		KeyOfType $bound,
	)
	{
		parent::__construct($bound->getType());
		$this->scope = $scope;
		$this->strategy = $templateTypeStrategy;
		$this->variance = $templateTypeVariance;
		$this->name = $name;
		$this->bound = $bound;
	}

	public function traverse(callable $cb): Type
	{
		$newBound = $cb($this->getBound());
		if ($this->getBound() !== $newBound && $newBound instanceof KeyOfType) {
			return new self(
				$this->scope,
				$this->strategy,
				$this->variance,
				$this->name,
				$newBound,
			);
		}

		return $this;
	}

	protected function shouldGeneralizeInferredType(): bool
	{
		return false;
	}

}
