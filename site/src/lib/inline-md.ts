/**
 * Render the inline-markdown subset frontmatter strings may use:
 * `code`, **bold**, *emphasis*, [link](href). Everything else is escaped.
 *
 * A link's href must be http(s), mailto, a root-relative path, or a fragment;
 * anything else is left as the escaped literal text it was written as.
 */
const SAFE_HREF = /^(https?:\/\/|mailto:|\/|#)/;

export function inlineMd(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (whole, text: string, href: string) =>
      SAFE_HREF.test(href) ? `<a href="${href.replace(/"/g, '&quot;')}">${text}</a>` : whole,
    );
}
