import { AtomsError } from './errors.js';
import { TransactionMachine, errorReply } from './bridge.js';

/**
 * JSPI resumes through promises, so its transaction callback must stay open
 * until the guest commits or rolls back. SQL uses the Durable Object's own
 * connection. The Asyncify backend retains TransactionMachine unchanged.
 */
export class TypePhpTransactions extends TransactionMachine {
	async begin(parked) {
		if (this.open) {
			parked.reply(errorReply('tx_state', 'a transaction is already open for this Atom'));
			return;
		}

		let end = null;
		this.open = true;
		try {
			await this.ctx.storage.transaction(async () => {
				parked.reply(JSON.stringify({ ok: true, opened: true }));
				end = await this.drainAsync();
				if (end.kind !== 'commit') throw this.SENTINEL;
			});
		} catch (error) {
			this.open = false;
			this.callbacks?.onTransactionRollback();
			if (error === this.SENTINEL && end?.kind === 'rollback') {
				end.parked.reply(JSON.stringify({ ok: true, rolledBack: true }));
				return;
			}
			if (error === this.SENTINEL && end?.kind === 'abandoned') {
				this.finishAbandoned(end.parked);
				return;
			}
			throw error instanceof AtomsError
				? error
				: new AtomsError('internal', `transaction failed: ${String(error)}`, { cause: error });
		} finally {
			this.open = false;
		}

		if (end?.kind !== 'commit') {
			throw new AtomsError('internal', 'transaction committed without a parked tx.commit');
		}
		this.callbacks?.onTransactionCommit();
		end.parked.reply(JSON.stringify({ ok: true, committed: true }));
	}

	async drainAsync() {
		for (let steps = 0; steps < this.config.maxTxParkSteps; steps++) {
			const parked = this.host.takePending()
				?? await this.host.waitForPark(this.config.parkWaitTimeoutMs);
			if (!parked) {
				throw new AtomsError('internal', 'the PHP runtime left the transaction without parking (guest fatal?)');
			}
			const op = typeof parked.msg.op === 'string' ? parked.msg.op : '(none)';
			if (op === 'tx.commit') return { kind: 'commit', parked };
			if (op === 'tx.rollback') return { kind: 'rollback', parked };
			if (op === 'turn.await') return { kind: 'abandoned', parked };
			// A JSPI reply schedules guest execution after this stack returns.
			// Keep the final park suspended when the drain budget is exhausted.
			// Otherwise the guest can write after rollback starts.
			if (steps + 1 === this.config.maxTxParkSteps) break;
			parked.reply(errorReply('tx_state', `park op ${JSON.stringify(op)} is not allowed while a database transaction is open`));
		}
		throw new AtomsError('internal', `transaction exceeded ATOMS_MAX_TX_PARK_STEPS (${this.config.maxTxParkSteps}) park ops`);
	}
}
