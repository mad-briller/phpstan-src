<?php declare(strict_types = 1);

namespace PHPStan\Rules\PhpDoc;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\Tag\TemplateTag;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Generics\TemplateTypeCheck;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\CallableType;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_keys;
use function sprintf;

class GenericCallableRuleHelper
{

	public function __construct(
		private TemplateTypeCheck $templateTypeCheck,
	)
	{
	}

	/**
	 * @param CallableType|ClosureType $callableType
	 * @param array<string, TemplateTag> $functionTemplateTags
	 * @param array<string, TemplateTag> $classTemplateTags
	 *
	 * @return array<RuleError>
	 */
	public function check(
		Node $node,
		Scope $scope,
		string $location,
		Type $callableType,
		string $functionName,
		array $functionTemplateTags,
		?ClassReflection $classReflection,
		array $classTemplateTags,
	): array
	{
		$typeDescription = $callableType->describe(VerbosityLevel::precise());

		$errors = $this->templateTypeCheck->check(
			$scope,
			$node,
			TemplateTypeScope::createWithAnonymousFunction(),
			$callableType->getTemplateTags(),
			// TODO: Name the parameter if it's a parameter.
			sprintf('PHPDoc tag %s template of %s cannot have existing class %%s as its name.', $location, $typeDescription),
			sprintf('PHPDoc tag %s template of %s cannot have existing type alias %%s as its name.', $location, $typeDescription),
			sprintf('PHPDoc tag %s template %%s of %s has invalid bound type %%s.', $location, $typeDescription),
			sprintf('PHPDoc tag %s template %%s of %s with bound type %%s is not supported.', $location, $typeDescription),
		);

		$templateTags = $callableType->getTemplateTags();

		$functionDescription = sprintf('function %s', $functionName);
		$classDescription = null;
		if ($classReflection !== null) {
			$classDescription = $classReflection->getDisplayName();
			$functionDescription = sprintf('method %s::%s', $classDescription, $functionName);
		}

		foreach (array_keys($functionTemplateTags) as $name) {
			if (!isset($templateTags[$name])) {
				continue;
			}

			$errors[] = RuleErrorBuilder::message(sprintf(
				'PHPDoc tag %s template %s of %s shadows @template %s for %s.',
				$location,
				$name,
				$typeDescription,
				$name,
				$functionDescription,
			))->build();
		}

		foreach (array_keys($classTemplateTags) as $name) {
			if (!isset($templateTags[$name])) {
				continue;
			}

			if ($classDescription === null) {
				throw new ShouldNotHappenException();
			}

			$errors[] = RuleErrorBuilder::message(sprintf(
				'PHPDoc tag %s template %s of %s shadows @template %s for class %s.',
				$location,
				$name,
				$typeDescription,
				$name,
				$classDescription,
			))->build();
		}

		return $errors;
	}

}
