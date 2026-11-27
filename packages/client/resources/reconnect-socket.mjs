/** Open a WebSocket with a fresh ticket on each attempt. Call the returned function to stop. */
export function reconnectSocket(getUrl, onSocket) {
    const controller = new AbortController();
    const { signal } = controller;
    let socket;
    let timer;
    let delay = 1000;

    function retry() {
        if (signal.aborted) return;
        timer = setTimeout(connect, delay);
        delay = Math.min(delay * 2, 15000);
    }

    async function connect() {
        try {
            const url = await getUrl(signal);
            if (signal.aborted || url === null) return;
            socket = new WebSocket(url);
        } catch {
            retry();
            return;
        }
        socket.addEventListener('message', () => { delay = 1000; }, { once: true });
        socket.addEventListener('close', ({ code }) => {
            if ([1001, 1005, 1006, 1011, 1012, 1013, 1014].includes(code)) retry();
        }, { once: true });
        onSocket(socket);
    }

    connect();
    return () => {
        controller.abort();
        clearTimeout(timer);
        socket?.close();
    };
}
