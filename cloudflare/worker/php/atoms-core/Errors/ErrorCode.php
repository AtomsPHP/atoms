<?php

declare(strict_types=1);

namespace Atoms\Errors;

/**
 * String-backed enum of every ATOMS-E### code in resources/errors.json.
 *
 * The JSON catalog is the single source of truth; this enum must stay in sync
 * with it (a core test enforces the bidirectional match). Append-only — never
 * renumber an existing case.
 */
enum ErrorCode: string
{
    case UnclassifiableFile = 'ATOMS-E001';
    case FrameworkSymbolInAtom = 'ATOMS-E010';
    case FrameworkHelperInAtom = 'ATOMS-E011';
    case MonolithClassInAtom = 'ATOMS-E012';
    case UndeclaredPackage = 'ATOMS-E013';
    case FacadeInAtom = 'ATOMS-E014';
    case SharedNonCoreSymbol = 'ATOMS-E015';
    case SharedContainsBehavior = 'ATOMS-E016';
    case EnvInAtom = 'ATOMS-E017';
    case NativeSerializationAtBoundary = 'ATOMS-E018';
    case ExtensionUnavailable = 'ATOMS-E019';
    case BoundaryTypeOutsideAlgebra = 'ATOMS-E020';
    case OrmObjectAtBoundary = 'ATOMS-E021';
    case UnserializableValue = 'ATOMS-E022';
    case PayloadNotHydratable = 'ATOMS-E023';
    case BoundaryTypeMismatch = 'ATOMS-E024';
    case UnknownMethodsMethod = 'ATOMS-E030';
    case MethodsSignatureMismatch = 'ATOMS-E031';
    case AtomJobSignatureMismatch = 'ATOMS-E032';
    case NotAnAtomJob = 'ATOMS-E033';
    case ManifestHashMismatch = 'ATOMS-E040';
    case MethodNotInDeployedVersion = 'ATOMS-E041';
    case BundleRejected = 'ATOMS-E042';
    case CoreVersionUnsupported = 'ATOMS-E043';
    case MigrationEdited = 'ATOMS-E050';
    case MigrationNumberingConflict = 'ATOMS-E051';
    case MigrationBudgetExceeded = 'ATOMS-E052';
    case MigrationFailed = 'ATOMS-E053';
    case AtomTypeNotDeployed = 'ATOMS-E060';
    case TurnDeadlineExceeded = 'ATOMS-E061';
    case CapacityRefused = 'ATOMS-E062';
    case RemoteAtomException = 'ATOMS-E063';
    case CallbackSignatureInvalid = 'ATOMS-E064';
    case CallbackReplayDetected = 'ATOMS-E065';
    case NoMethodsClassForCallback = 'ATOMS-E066';
    case AtomsJsonInvalid = 'ATOMS-E070';
    case AtomsComposerJsonInvalid = 'ATOMS-E071';
    case DeployCredentialsMissing = 'ATOMS-E072';
}
