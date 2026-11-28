// Installed only in the disposable test scaffold. Observe the actual callback
// passed to workerd, the Asyncify reply calls and the SQL operations they drive.
export function traceTransactions(Bridge, TransactionMachine) {
    let inside = false;
    function event(name) {
        console.log('PDO_PROBE_TRACE ' + name + (inside ? ' inside' : ' outside'));
    }
    function traceReply(parked) {
        return {
            ...parked,
            reply(value) {
                event('resume.' + parked.msg.op);
                return parked.reply(value);
            },
        };
    }

    const begin = TransactionMachine.prototype.begin;
    TransactionMachine.prototype.begin = function (parked) {
        const ctx = this.ctx;
        this.ctx = {
            storage: {
                transactionSync(fn) {
                    return ctx.storage.transactionSync(() => {
                        inside = true;
                        try {
                            return fn();
                        } finally {
                            inside = false;
                        }
                    });
                },
            },
        };
        try {
            return begin.call(this, traceReply(parked));
        } finally {
            this.ctx = ctx;
        }
    };
    const drain = TransactionMachine.prototype.drain;
    TransactionMachine.prototype.drain = function () {
        const end = drain.call(this);
        return { ...end, parked: traceReply(end.parked) };
    };
    const sql = Bridge.prototype.opSqlExec;
    Bridge.prototype.opSqlExec = function (msg) {
        if (msg.sql.includes('INSERT INTO probe_rows')) event('sql.insert');
        if (msg.sql.includes('SELECT value FROM probe_rows')) event('sql.read');
        return sql.call(this, msg);
    };
}
