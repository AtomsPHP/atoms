// A required capability turns a missing prerequisite into a failure. Keep the
// environment-variable names here so all whole checks and partial legs use
// the same interpretation of the six flags.
export const REQUIRED_CAPABILITIES = Object.freeze({
    callback: 'ATOMS_REQUIRE_CALLBACK_CHECKS',
    sqlCaps: 'ATOMS_REQUIRE_SQL_CAP_CHECKS',
    tickets: 'ATOMS_REQUIRE_TICKET_CHECKS',
    bearerVector: 'ATOMS_REQUIRE_BEARER_VECTOR',
    rotation: 'ATOMS_REQUIRE_ROTATION_CHECKS',
    deny: 'ATOMS_REQUIRE_DENY_CHECKS',
});

export function isRequired(capability, env = process.env) {
    const name = REQUIRED_CAPABILITIES[capability];
    if (!name) throw new Error(`Unknown required capability: ${capability}`);
    return /^(1|true|yes|on)$/i.test(env[name] || '');
}

export function recordMissingLeg(capability, problems, notes, failure, skipped) {
    const required = isRequired(capability);
    (required ? problems : notes).push(required ? failure : skipped);
}
