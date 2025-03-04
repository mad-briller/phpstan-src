<?php declare(strict_types = 1);

namespace PHPStan\Rules\Functions;

use PHPStan\Php\PhpVersion;
use PHPStan\Rules\ClassCaseSensitivityCheck;
use PHPStan\Rules\FunctionDefinitionCheck;
use PHPStan\Rules\PhpDoc\UnresolvableTypeHelper;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<ExistingClassesInClosureTypehintsRule>
 */
class ExistingClassesInClosureTypehintsRuleTest extends RuleTestCase
{

	private int $phpVersionId = PHP_VERSION_ID;

	protected function getRule(): Rule
	{
		$broker = $this->createReflectionProvider();
		return new ExistingClassesInClosureTypehintsRule(new FunctionDefinitionCheck($broker, new ClassCaseSensitivityCheck($broker, true), new UnresolvableTypeHelper(), new PhpVersion($this->phpVersionId), true, false));
	}

	public function testExistingClassInTypehint(): void
	{
		$this->analyse([__DIR__ . '/data/closure-typehints.php'], [
			[
				'Anonymous function has invalid return type TestClosureFunctionTypehints\NonexistentClass.',
				10,
			],
			[
				'Parameter $bar of anonymous function has invalid type TestClosureFunctionTypehints\BarFunctionTypehints.',
				15,
			],
			[
				'Class TestClosureFunctionTypehints\FooFunctionTypehints referenced with incorrect case: TestClosureFunctionTypehints\fOOfUnctionTypehints.',
				30,
			],
			[
				'Class TestClosureFunctionTypehints\FooFunctionTypehints referenced with incorrect case: TestClosureFunctionTypehints\FOOfUnctionTypehintS.',
				30,
			],
			[
				'Parameter $trait of anonymous function has invalid type TestClosureFunctionTypehints\SomeTrait.',
				45,
			],
			[
				'Anonymous function has invalid return type TestClosureFunctionTypehints\SomeTrait.',
				50,
			],
		]);
	}

	public function testValidTypehintPhp71(): void
	{
		$this->analyse([__DIR__ . '/data/closure-7.1-typehints.php'], [
			[
				'Parameter $bar of anonymous function has invalid type TestClosureFunctionTypehintsPhp71\NonexistentClass.',
				35,
			],
			[
				'Anonymous function has invalid return type TestClosureFunctionTypehintsPhp71\NonexistentClass.',
				35,
			],
		]);
	}

	public function testValidTypehintPhp72(): void
	{
		$this->analyse([__DIR__ . '/data/closure-7.2-typehints.php'], []);
	}

	public function testVoidParameterTypehint(): void
	{
		$this->analyse([__DIR__ . '/data/void-parameter-typehint.php'], [
			[
				'Parameter $param of anonymous function has invalid type void.',
				5,
			],
		]);
	}

	public function dataNativeUnionTypes(): array
	{
		return [
			[
				70400,
				[
					[
						'Anonymous function uses native union types but they\'re supported only on PHP 8.0 and later.',
						15,
					],
					[
						'Anonymous function uses native union types but they\'re supported only on PHP 8.0 and later.',
						19,
					],
				],
			],
			[
				80000,
				[],
			],
		];
	}

	/**
	 * @dataProvider dataNativeUnionTypes
	 * @param mixed[] $errors
	 */
	public function testNativeUnionTypes(int $phpVersionId, array $errors): void
	{
		$this->phpVersionId = $phpVersionId;
		$this->analyse([__DIR__ . '/data/native-union-types.php'], $errors);
	}

	public function dataRequiredParameterAfterOptional(): array
	{
		return [
			[
				70400,
				[],
			],
			[
				80000,
				[
					[
						'Deprecated in PHP 8.0: Required parameter $bar follows optional parameter $foo.',
						5,
					],
					[
						'Deprecated in PHP 8.0: Required parameter $bar follows optional parameter $foo.',
						13,
					],
					[
						'Deprecated in PHP 8.0: Required parameter $bar follows optional parameter $foo.',
						17,
					],
				],
			],
		];
	}

	/**
	 * @dataProvider dataRequiredParameterAfterOptional
	 * @param mixed[] $errors
	 */
	public function testRequiredParameterAfterOptional(int $phpVersionId, array $errors): void
	{
		$this->phpVersionId = $phpVersionId;
		$this->analyse([__DIR__ . '/data/required-parameter-after-optional-closures.php'], $errors);
	}

	public function dataIntersectionTypes(): array
	{
		return [
			[80000, []],
			[
				80100,
				[
					[
						'Parameter $a of anonymous function has unresolvable native type.',
						30,
					],
					[
						'Anonymous function has unresolvable native return type.',
						30,
					],
					[
						'Parameter $a of anonymous function has unresolvable native type.',
						35,
					],
					[
						'Anonymous function has unresolvable native return type.',
						35,
					],
				],
			],
		];
	}

	/**
	 * @dataProvider dataIntersectionTypes
	 * @param mixed[] $errors
	 */
	public function testIntersectionTypes(int $phpVersion, array $errors): void
	{
		$this->phpVersionId = $phpVersion;

		$this->analyse([__DIR__ . '/data/closure-intersection-types.php'], $errors);
	}

}
