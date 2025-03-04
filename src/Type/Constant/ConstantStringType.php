<?php declare(strict_types = 1);

namespace PHPStan\Type\Constant;

use Nette\Utils\RegexpException;
use Nette\Utils\Strings;
use PhpParser\Node\Name;
use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\InaccessibleMethod;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Reflection\TrivialParametersAcceptor;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryLiteralStringType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\ConstantScalarType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StringType;
use PHPStan\Type\Traits\ConstantScalarTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use function is_float;
use function is_numeric;
use function strlen;
use function substr;
use function var_export;

/** @api */
class ConstantStringType extends StringType implements ConstantScalarType
{

	private const DESCRIBE_LIMIT = 20;

	use ConstantScalarTypeTrait;
	use ConstantScalarToBooleanTrait;

	private ?ObjectType $objectType = null;

	/** @api */
	public function __construct(private string $value, private bool $isClassString = false)
	{
		parent::__construct();
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function isClassString(): bool
	{
		if ($this->isClassString) {
			return true;
		}

		$reflectionProvider = ReflectionProviderStaticAccessor::getInstance();

		return $reflectionProvider->hasClass($this->value);
	}

	public function describe(VerbosityLevel $level): string
	{
		return $level->handle(
			static fn (): string => 'string',
			function (): string {
				if ($this->isClassString) {
					return var_export($this->value, true);
				}

				try {
					$truncatedValue = Strings::truncate($this->value, self::DESCRIBE_LIMIT);
				} catch (RegexpException) {
					$truncatedValue = substr($this->value, 0, self::DESCRIBE_LIMIT) . "\u{2026}";
				}

				return var_export(
					$truncatedValue,
					true,
				);
			},
			fn (): string => var_export($this->value, true),
		);
	}

	public function isSuperTypeOf(Type $type): TrinaryLogic
	{
		if ($type instanceof GenericClassStringType) {
			$genericType = $type->getGenericType();
			if ($genericType instanceof MixedType) {
				return TrinaryLogic::createMaybe();
			}
			if ($genericType instanceof StaticType) {
				$genericType = $genericType->getStaticObjectType();
			}

			// We are transforming constant class-string to ObjectType. But we need to filter out
			// an uncertainty originating in possible ObjectType's class subtypes.
			$objectType = $this->getObjectType();

			// Do not use TemplateType's isSuperTypeOf handling directly because it takes ObjectType
			// uncertainty into account.
			if ($genericType instanceof TemplateType) {
				$isSuperType = $genericType->getBound()->isSuperTypeOf($objectType);
			} else {
				$isSuperType = $genericType->isSuperTypeOf($objectType);
			}

			// Explicitly handle the uncertainty for Yes & Maybe.
			if ($isSuperType->yes()) {
				return TrinaryLogic::createMaybe();
			}
			return TrinaryLogic::createNo();
		}
		if ($type instanceof ClassStringType) {
			return $this->isClassString() ? TrinaryLogic::createMaybe() : TrinaryLogic::createNo();
		}

		if ($type instanceof self) {
			return $this->value === $type->value ? TrinaryLogic::createYes() : TrinaryLogic::createNo();
		}

		if ($type instanceof parent) {
			return TrinaryLogic::createMaybe();
		}

		if ($type instanceof CompoundType) {
			return $type->isSubTypeOf($this);
		}

		return TrinaryLogic::createNo();
	}

	public function isCallable(): TrinaryLogic
	{
		if ($this->value === '') {
			return TrinaryLogic::createNo();
		}

		$reflectionProvider = ReflectionProviderStaticAccessor::getInstance();

		// 'my_function'
		if ($reflectionProvider->hasFunction(new Name($this->value), null)) {
			return TrinaryLogic::createYes();
		}

		// 'MyClass::myStaticFunction'
		$matches = Strings::match($this->value, '#^([a-zA-Z_\\x7f-\\xff\\\\][a-zA-Z0-9_\\x7f-\\xff\\\\]*)::([a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*)\z#');
		if ($matches !== null) {
			if (!$reflectionProvider->hasClass($matches[1])) {
				return TrinaryLogic::createMaybe();
			}

			$classRef = $reflectionProvider->getClass($matches[1]);
			if ($classRef->hasMethod($matches[2])) {
				return TrinaryLogic::createYes();
			}

			if (!$classRef->getNativeReflection()->isFinal()) {
				return TrinaryLogic::createMaybe();
			}

			return TrinaryLogic::createNo();
		}

		return TrinaryLogic::createNo();
	}

	/**
	 * @return ParametersAcceptor[]
	 */
	public function getCallableParametersAcceptors(ClassMemberAccessAnswerer $scope): array
	{
		$reflectionProvider = ReflectionProviderStaticAccessor::getInstance();

		// 'my_function'
		$functionName = new Name($this->value);
		if ($reflectionProvider->hasFunction($functionName, null)) {
			return $reflectionProvider->getFunction($functionName, null)->getVariants();
		}

		// 'MyClass::myStaticFunction'
		$matches = Strings::match($this->value, '#^([a-zA-Z_\\x7f-\\xff\\\\][a-zA-Z0-9_\\x7f-\\xff\\\\]*)::([a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*)\z#');
		if ($matches !== null) {
			if (!$reflectionProvider->hasClass($matches[1])) {
				return [new TrivialParametersAcceptor()];
			}

			$classReflection = $reflectionProvider->getClass($matches[1]);
			if ($classReflection->hasMethod($matches[2])) {
				$method = $classReflection->getMethod($matches[2], $scope);
				if (!$scope->canCallMethod($method)) {
					return [new InaccessibleMethod($method)];
				}

				return $method->getVariants();
			}

			if (!$classReflection->getNativeReflection()->isFinal()) {
				return [new TrivialParametersAcceptor()];
			}
		}

		throw new ShouldNotHappenException();
	}

	public function toNumber(): Type
	{
		if (is_numeric($this->value)) {
			/** @var mixed $value */
			$value = $this->value;
			$value = +$value;
			if (is_float($value)) {
				return new ConstantFloatType($value);
			}

			return new ConstantIntegerType($value);
		}

		return new ErrorType();
	}

	public function toInteger(): Type
	{
		return new ConstantIntegerType((int) $this->value);
	}

	public function toFloat(): Type
	{
		return new ConstantFloatType((float) $this->value);
	}

	public function isString(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isNumericString(): TrinaryLogic
	{
		return TrinaryLogic::createFromBoolean(is_numeric($this->getValue()));
	}

	public function isNonEmptyString(): TrinaryLogic
	{
		return TrinaryLogic::createFromBoolean($this->getValue() !== '');
	}

	public function isLiteralString(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		if ($offsetType instanceof ConstantIntegerType) {
			return TrinaryLogic::createFromBoolean(
				$offsetType->getValue() < strlen($this->value),
			);
		}

		return parent::hasOffsetValueType($offsetType);
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		if ($offsetType instanceof ConstantIntegerType) {
			if ($offsetType->getValue() < strlen($this->value)) {
				return new self($this->value[$offsetType->getValue()]);
			}

			return new ErrorType();
		}

		return parent::getOffsetValueType($offsetType);
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType, bool $unionValues = true): Type
	{
		$valueStringType = $valueType->toString();
		if ($valueStringType instanceof ErrorType) {
			return new ErrorType();
		}
		if (
			$offsetType instanceof ConstantIntegerType
			&& $valueStringType instanceof ConstantStringType
		) {
			$value = $this->value;
			$offsetValue = $offsetType->getValue();
			if ($offsetValue < 0) {
				return new ErrorType();
			}
			$stringValue = $valueStringType->getValue();
			if (strlen($stringValue) !== 1) {
				return new ErrorType();
			}
			$value[$offsetValue] = $stringValue;

			return new self($value);
		}

		return parent::setOffsetValueType($offsetType, $valueType);
	}

	public function append(self $otherString): self
	{
		return new self($this->getValue() . $otherString->getValue());
	}

	public function generalize(GeneralizePrecision $precision): Type
	{
		if ($this->isClassString) {
			if ($precision->isMoreSpecific()) {
				return new ClassStringType();
			}

			return new StringType();
		}

		if ($this->getValue() !== '' && $precision->isMoreSpecific()) {
			return new IntersectionType([
				new StringType(),
				new AccessoryNonEmptyStringType(),
				new AccessoryLiteralStringType(),
			]);
		}

		if ($precision->isMoreSpecific()) {
			return new IntersectionType([
				new StringType(),
				new AccessoryLiteralStringType(),
			]);
		}

		return new StringType();
	}

	public function getSmallerType(): Type
	{
		$subtractedTypes = [
			new ConstantBooleanType(true),
			IntegerRangeType::createAllGreaterThanOrEqualTo((float) $this->value),
		];

		if ($this->value === '') {
			$subtractedTypes[] = new NullType();
			$subtractedTypes[] = new StringType();
		}

		if (!(bool) $this->value) {
			$subtractedTypes[] = new ConstantBooleanType(false);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	public function getSmallerOrEqualType(): Type
	{
		$subtractedTypes = [
			IntegerRangeType::createAllGreaterThan((float) $this->value),
		];

		if (!(bool) $this->value) {
			$subtractedTypes[] = new ConstantBooleanType(true);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	public function getGreaterType(): Type
	{
		$subtractedTypes = [
			new ConstantBooleanType(false),
			IntegerRangeType::createAllSmallerThanOrEqualTo((float) $this->value),
		];

		if ((bool) $this->value) {
			$subtractedTypes[] = new ConstantBooleanType(true);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	public function getGreaterOrEqualType(): Type
	{
		$subtractedTypes = [
			IntegerRangeType::createAllSmallerThan((float) $this->value),
		];

		if ((bool) $this->value) {
			$subtractedTypes[] = new ConstantBooleanType(false);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	public function canAccessConstants(): TrinaryLogic
	{
		return TrinaryLogic::createFromBoolean($this->isClassString());
	}

	public function hasConstant(string $constantName): TrinaryLogic
	{
		return $this->getObjectType()->hasConstant($constantName);
	}

	public function getConstant(string $constantName): ConstantReflection
	{
		return $this->getObjectType()->getConstant($constantName);
	}

	private function getObjectType(): ObjectType
	{
		return $this->objectType ??= new ObjectType($this->value);
	}

	/**
	 * @param mixed[] $properties
	 */
	public static function __set_state(array $properties): Type
	{
		return new self($properties['value'], $properties['isClassString'] ?? false);
	}

}
