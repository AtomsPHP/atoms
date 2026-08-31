/**
 * The homepage code samples — single source of truth for both renditions.
 *
 * `html` is the hand-highlighted markup (brand token classes, `data-a` anchors
 * for the annotation rail) rendered into index.astro's <pre> blocks; the markdown
 * endpoint derives plain code from the same strings via plain(). Every sample
 * must use real atoms/* API surfaces — verify against packages/ when editing.
 */

export interface Sample {
  title: string;
  lang: 'php' | 'js' | 'sh';
  html: string;
}

export const samples = {
  gameRoomEloquent: {
    title: 'app/Atoms/GameRoom.php',
    lang: 'php',
    html: `<span class="tok-k">namespace</span> App\\Atoms;

<span class="tok-k">use</span> Atoms\\Atom;
<span class="tok-k">use</span> Atoms\\DatabaseIlluminate\\EloquentBridge;
<span class="tok-k">use</span> Atoms\\Websocket\\Connection;
<span class="tok-k">use</span> Atoms\\Websocket\\Message;

<span class="tok-k">class</span> <span class="tok-f">GameRoom</span> <span class="tok-k">extends</span> Atom
{
    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-join">join</span>(<span class="tok-k">string</span> $player): <span class="tok-k">int</span>
    {
        $db = EloquentBridge::boot($this-&gt;db());

        $seat = $db-&gt;transaction(<span class="tok-k">function</span> () <span class="tok-k">use</span> ($player): <span class="tok-k">int</span> {
            $taken = Player::query()-&gt;count();

            <span class="tok-k">if</span> ($taken &gt;= 4) {
                <span class="tok-k">throw new</span> \\DomainException(<span class="tok-s">'room_full'</span>);
            }

            Player::create([<span class="tok-s">'name'</span> =&gt; $player, <span class="tok-s">'seat'</span> =&gt; $taken + 1]);

            <span class="tok-k">return</span> $taken + 1;
        });

        $this-&gt;broadcast(<span class="tok-s">'room'</span>, [<span class="tok-s">'kind'</span> =&gt; <span class="tok-s">'joined'</span>, <span class="tok-s">'player'</span> =&gt; $player, <span class="tok-s">'seat'</span> =&gt; $seat]);

        <span class="tok-k">return</span> $seat;
    }

    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-onconnect">onConnect</span>(Connection $conn, <span class="tok-k">array</span> $params): <span class="tok-k">void</span>
    {
        EloquentBridge::boot($this-&gt;db());

        Player::where(<span class="tok-s">'name'</span>, $params[<span class="tok-s">'player'</span>])
            -&gt;update([<span class="tok-s">'connection_id'</span> =&gt; $conn-&gt;id()]);

        $conn-&gt;sendJson([
            <span class="tok-s">'kind'</span>  =&gt; <span class="tok-s">'welcome'</span>,
            <span class="tok-s">'board'</span> =&gt; Piece::all([<span class="tok-s">'piece'</span>, <span class="tok-s">'square'</span>, <span class="tok-s">'owner'</span>])-&gt;toArray(),
        ]);
    }

    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-onmessage">onMessage</span>(Connection $conn, Message $msg): <span class="tok-k">void</span>
    {
        EloquentBridge::boot($this-&gt;db());

        $move  = $msg-&gt;json();
        $mover = Player::where(<span class="tok-s">'connection_id'</span>, $conn-&gt;id())-&gt;first();

        $moved = Piece::where(<span class="tok-s">'piece'</span>, $move[<span class="tok-s">'piece'</span>])
            -&gt;where(<span class="tok-s">'owner'</span>, $mover?-&gt;name)
            -&gt;update([<span class="tok-s">'square'</span> =&gt; $move[<span class="tok-s">'square'</span>]]);

        <span class="tok-k">if</span> ($moved === 1) {
            $this-&gt;broadcast(<span class="tok-s">'room'</span>, [<span class="tok-s">'kind'</span> =&gt; <span class="tok-s">'moved'</span>] + $move);
        }
    }
}`,
  },
  gameRoomSql: {
    title: 'app/Atoms/GameRoom.php',
    lang: 'php',
    html: `<span class="tok-k">namespace</span> App\\Atoms;

<span class="tok-k">use</span> Atoms\\Atom;
<span class="tok-k">use</span> Atoms\\Database;
<span class="tok-k">use</span> Atoms\\Websocket\\Connection;
<span class="tok-k">use</span> Atoms\\Websocket\\Message;

<span class="tok-k">class</span> <span class="tok-f">GameRoom</span> <span class="tok-k">extends</span> Atom
{
    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-join">join</span>(<span class="tok-k">string</span> $player): <span class="tok-k">int</span>
    {
        $seat = $this-&gt;db()-&gt;transaction(<span class="tok-k">function</span> (Database $db) <span class="tok-k">use</span> ($player): <span class="tok-k">int</span> {
            $taken = (<span class="tok-k">int</span>) $db-&gt;query(<span class="tok-s">'SELECT COUNT(*) c FROM players'</span>)[0][<span class="tok-s">'c'</span>];

            <span class="tok-k">if</span> ($taken &gt;= 4) {
                <span class="tok-k">throw new</span> \\DomainException(<span class="tok-s">'room_full'</span>);
            }

            $db-&gt;execute(<span class="tok-s">'INSERT INTO players (name, seat) VALUES (?, ?)'</span>, [$player, $taken + 1]);

            <span class="tok-k">return</span> $taken + 1;
        });

        $this-&gt;broadcast(<span class="tok-s">'room'</span>, [<span class="tok-s">'kind'</span> =&gt; <span class="tok-s">'joined'</span>, <span class="tok-s">'player'</span> =&gt; $player, <span class="tok-s">'seat'</span> =&gt; $seat]);

        <span class="tok-k">return</span> $seat;
    }

    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-onconnect">onConnect</span>(Connection $conn, <span class="tok-k">array</span> $params): <span class="tok-k">void</span>
    {
        $this-&gt;db()-&gt;execute(
            <span class="tok-s">'UPDATE players SET connection_id = ? WHERE name = ?'</span>,
            [$conn-&gt;id(), $params[<span class="tok-s">'player'</span>]]
        );

        $conn-&gt;sendJson([
            <span class="tok-s">'kind'</span>  =&gt; <span class="tok-s">'welcome'</span>,
            <span class="tok-s">'board'</span> =&gt; $this-&gt;db()-&gt;query(<span class="tok-s">'SELECT piece, square, owner FROM board'</span>),
        ]);
    }

    <span class="tok-k">public function</span> <span class="tok-f" data-a="a-onmessage">onMessage</span>(Connection $conn, Message $msg): <span class="tok-k">void</span>
    {
        $move = $msg-&gt;json();

        $moved = $this-&gt;db()-&gt;execute(
            <span class="tok-s">'UPDATE board SET square = ?
              WHERE piece = ?
                AND owner = (SELECT name FROM players WHERE connection_id = ?)'</span>,
            [$move[<span class="tok-s">'square'</span>], $move[<span class="tok-s">'piece'</span>], $conn-&gt;id()]
        );

        <span class="tok-k">if</span> ($moved === 1) {
            $this-&gt;broadcast(<span class="tok-s">'room'</span>, [<span class="tok-s">'kind'</span> =&gt; <span class="tok-s">'moved'</span>] + $move);
        }
    }
}`,
  },
  callSite: {
    title: 'anywhere in your app',
    lang: 'php',
    html: `$seat = Atoms::get(GameRoom::class, <span class="tok-s">'room-42'</span>)-&gt;join(<span class="tok-s">'ada'</span>);`,
  },
  browser: {
    title: 'in the browser',
    lang: 'js',
    html: `<span class="tok-k">const</span> { url } = <span class="tok-k">await</span> fetch(<span class="tok-s">'/api/rooms/room-42/socket'</span>, { method: <span class="tok-s">'POST'</span> })
  .then((res) =&gt; res.json());

<span class="tok-k">const</span> ws = <span class="tok-k">new</span> WebSocket(url);
ws.onmessage = (e) =&gt; drawBoard(JSON.parse(e.data));

<span class="tok-c">// a player moves a piece</span>
ws.send(JSON.stringify({ piece: <span class="tok-s">'rook'</span>, square: <span class="tok-s">'d4'</span> }));`,
  },
  route: {
    title: 'routes/api.php — a normal authenticated route',
    lang: 'php',
    html: `Route::middleware(<span class="tok-s">'auth'</span>)-&gt;post(<span class="tok-s">'/rooms/{room}/socket'</span>, <span class="tok-k">function</span> (<span class="tok-k">string</span> $room) {
    <span class="tok-k">return</span> [<span class="tok-s">'url'</span> =&gt; Atoms::wsUrl(GameRoom::class, $room, [
        <span class="tok-s">'channels'</span> =&gt; [<span class="tok-s">'room'</span>],
        <span class="tok-s">'ticket'</span>   =&gt; (<span class="tok-k">string</span>) Atoms::ticket(GameRoom::class, $room, [
            <span class="tok-s">'player'</span> =&gt; auth()-&gt;user()-&gt;name,
        ]),
    ])];
});`,
  },
  install: {
    title: '',
    lang: 'sh',
    html: `<span class="tok-c">$</span> composer require atoms/laravel
<span class="tok-c">$ # If you're working in Laravel:</span>
<span class="tok-c">$</span> php artisan atoms:install

<span class="tok-c">$</span> <span class="tok-c"># Scaffold the Worker project:</span>
<span class="tok-c">$</span> npm exec --yes --package=@atomsphp/runtime-cloudflare -- \\
    atoms-runtime-cloudflare init .atoms/worker
<span class="tok-c">$</span> <span class="tok-c"># Install JavaScript dependencies:</span>
<span class="tok-c">$</span> (cd .atoms/worker &amp;&amp; npm ci)

<span class="tok-c">$</span> vendor/bin/atoms dev
<span class="tok-c">$</span> vendor/bin/atoms deploy`,
  },
} satisfies Record<string, Sample>;

/** Plain code from a sample's highlighted HTML: strip tags, decode entities. */
export function plain(html: string): string {
  return html
    .replace(/<[^>]+>/g, '')
    .replace(/&gt;/g, '>')
    .replace(/&lt;/g, '<')
    .replace(/&amp;/g, '&');
}
