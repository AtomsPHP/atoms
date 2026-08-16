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
     * `wrangler deploy --name {worker}`, with `--var` pairs injected — the
     * same override channel `dev()` uses, so a setting declared once in
     * atoms.json reaches both. The Worker project directory is the working
     * directory, so its wrangler config and `src/` are what ships.
     *
     * @param array<string, string> $vars
     */
    public function deploy(CloudflareTarget $target, array $vars = []): WranglerResult;

    /**
     * `wrangler versions list --name {worker} --json`.
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
     * `wrangler dev --port {port}`, with `--var` pairs injected. Runs in the
     * foreground until interrupted.
     *
     * @param array<string, string> $vars
     */
    public function dev(CloudflareTarget $target, string $port, array $vars): WranglerResult;
}
