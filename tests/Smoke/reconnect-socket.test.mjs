import assert from 'node:assert/strict';
import { test } from 'node:test';
import { reconnectSocket } from '../../packages/client/resources/reconnect-socket.mjs';

test('stopping during a ticket request cancels it without opening a socket', async () => {
    const ticket = Promise.withResolvers();
    let signal;
    let sockets = 0;
    const stop = reconnectSocket((requestSignal) => {
        signal = requestSignal;
        return ticket.promise;
    }, () => sockets++);
    stop();
    assert.equal(signal.aborted, true);
    ticket.resolve('ws://localhost/');
    await ticket.promise;
    assert.equal(sockets, 0);
});

for (const code of [1000, 4403]) {
    test(`close code ${code} ends ticket requests`, async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        class Socket extends EventTarget {}
        t.mock.method(globalThis, 'WebSocket', Socket);
        let requests = 0;
        let socket;
        reconnectSocket(() => {
            requests++;
            return 'ws://localhost/';
        }, (opened) => { socket = opened; });
        await Promise.resolve();
        socket.dispatchEvent(Object.assign(new Event('close'), { code }));
        t.mock.timers.tick(60000);
        assert.equal(requests, 1);
    });
}

test('a failed ticket request retries and a null URL ends retries', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let requests = 0;
    reconnectSocket(() => {
        if (++requests === 1) throw new Error('Ticket endpoint unavailable');
        return null;
    }, () => assert.fail('No ticket was issued'));
    t.mock.timers.tick(1000);
    await Promise.resolve();
    t.mock.timers.tick(60000);
    assert.equal(requests, 2);
});
