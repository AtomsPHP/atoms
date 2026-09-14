<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

/**
 * The seam over Wrangler, so the deploy/status/rollback/secrets commands can be
 * driven by a fake in tests without spawning a subprocess or touching the
 * network — the same discipline `ProcessRunner` already gives the build stage.
 *
 * Every method takes the {@see CloudflareTarget} rather than loose strings: the
 * credentials must reach the child process environment and nowhere else, and
 * routing them through one object is what makes that reviewable.
 */
interface Wrangler
{
    /**
     * `wrangler deploy`, in the Worker project directory. The Worker name,
     * runtime vars and routing are not flags: the caller has already written
     * the environment's {@see GeneratedWranglerConfig}, which Wrangler picks
     * up through `.wrangler/deploy/config.json` in that directory.
     */
    public function deploy(CloudflareTarget $target): WranglerResult;

    /**
     * `wrangler versions list --name {worker} --json`.
     *
     * This and the commands below still name the Worker on the command line:
     * Wrangler consults the generated-config redirect only for `deploy`,
     * `dev` and `versions upload`/`deploy`, so `--name` is what selects the
     * environment's Worker here.
     */
    public function versions(CloudflareTarget $target): WranglerResult;

    /**
     * `wrangler rollback [version-id] --name {worker} --yes`. A null version
     * rolls back to the previous one, which is Wrangler's own default.
     */
    public function rollback(CloudflareTarget $target, ?string $versionId, ?string $message): WranglerResult;

    /**
     * `wrangler secret put {key} --name {worker}`, value on stdin so it never
     * appears in an argv a process listing could show.
     */
    public function putSecret(CloudflareTarget $target, string $key, string $value): WranglerResult;

    /**
     * `wrangler secret list --name {worker} --format json`.
     */
    public function listSecrets(CloudflareTarget $target): WranglerResult;

    /**
     * `wrangler secret delete {key} --name {worker}`. Wrangler asks for
     * confirmation and answers itself with `yes` when it is not attached to a
     * TTY, which is every way this seam runs it.
     */
    public function deleteSecret(CloudflareTarget $target, string $key): WranglerResult;

    /**
     * `wrangler dev --port {port}`, reading the same generated config as
     * `deploy()` does. Runs in the foreground until interrupted.
     */
    public function dev(CloudflareTarget $target, string $port): WranglerResult;
}
