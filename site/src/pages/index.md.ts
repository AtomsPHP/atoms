import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import { samples, plain } from '../samples';

/**
 * The homepage as markdown, for agents and anyone else who asks — served at
 * /index.md, and at / when the request carries Accept: text/markdown (see
 * functions/_middleware.js). Assembled from the same content collection and
 * code samples as the HTML page, so the two renditions cannot drift.
 */

function stripHtml(html: string): string {
  return html
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&gt;/g, '>')
    .replace(/&lt;/g, '<')
    .replace(/&amp;/g, '&');
}

function fence(sample: { lang: string; html: string }): string {
  return '```' + sample.lang + '\n' + plain(sample.html) + '\n```';
}

function links(ctas: { label: string; href: string }[] | undefined): string {
  return (ctas ?? []).map((c) => `[${c.label}](${c.href})`).join(' · ');
}

export const GET: APIRoute = async () => {
  const entries = await getCollection('home');
  const byId = Object.fromEntries(entries.map((e) => [e.id, e]));
  const need = (id: string) => {
    const e = byId[id];
    if (!e) throw new Error(`missing src/content/home/${id}.md`);
    return e;
  };

  const hero = need('hero');
  const code = need('code');
  const live = need('live');
  const auth = need('auth');
  const how = need('how');
  const why = need('why');
  const faq = need('faq');
  const start = need('start');

  const body = (e: { body?: string }) => (e.body ?? '').trim();

  const md = `# ${stripHtml(hero.data.title_html ?? '')}

${body(hero)}

${links(hero.data.ctas)}

## ${code.data.title}

${body(code)}

**${samples.gameRoomEloquent.title} — with Eloquent (\`atoms/database-illuminate\`)**

${fence(samples.gameRoomEloquent)}

**${samples.gameRoomSql.title} — with plain SQL**

${fence(samples.gameRoomSql)}

**${samples.callSite.title}**

${fence(samples.callSite)}

${(code.data.annotations ?? []).map((a) => `- **${a.method}** — ${a.text}`).join('\n')}

${code.data.caption ?? ''}

## ${live.data.title}

${body(live)}

**${samples.browser.title}**

${fence(samples.browser)}

${live.data.caption ?? ''}

## ${auth.data.title}

${body(auth)}

**${samples.route.title}**

${fence(samples.route)}

${auth.data.caption ?? ''}

## ${how.data.title}

${body(how)}

${(how.data.stations ?? []).map((s, i) => `${i + 1}. **${s.label}** — ${s.text}`).join('\n')}

${how.data.note ?? ''}

## ${why.data.title}

${(why.data.quads ?? []).map((q) => `- **${q.title}** — ${q.text}`).join('\n')}

## ${faq.data.title}

${body(faq)}

## ${start.data.title}

${fence(samples.install)}

${links(start.data.ctas)}
`;

  return new Response(md, {
    headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
  });
};
