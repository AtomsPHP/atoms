// This release-owned file is also shipped in the public Worker scaffold.
import pins from '../../release/interpreters.json' with { type: 'json' };

export const PHP_RUNTIMES = pins;

export function phpRuntime(series) {
	if (typeof series !== 'string' || !Object.hasOwn(pins, series)) {
		throw new Error(`Unsupported Worker PHP version ${JSON.stringify(series)}. Choose ${Object.keys(pins).join(', ')}.`);
	}
	const pin = pins[series];
	return { ...pin, php: pin.version.split('-')[0] };
}
