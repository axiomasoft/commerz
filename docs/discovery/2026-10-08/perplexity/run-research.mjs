// Reuses the installed local package's daemon client; no credentials are read or emitted.
// Run: node run-research.mjs 01-typed-catalog [02-inventory-reservations ...]
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseFlags, loadConfig, resolvePoolName } from '/home/vostrikov/projects/packages/perplexity-web-mcp/dist/config.js';
import { resolvePool } from '/home/vostrikov/projects/packages/perplexity-web-mcp/dist/pool.js';
import { createDaemonDispatcher } from '/home/vostrikov/projects/packages/perplexity-web-mcp/dist/daemon/client.js';
import { ensureChromiumExecutableEnv } from '/home/vostrikov/projects/packages/perplexity-web-mcp/dist/chromium-path.js';

const localPackage = '/home/vostrikov/projects/packages/perplexity-web-mcp';
const outputDir = dirname(fileURLToPath(import.meta.url));
const queries = JSON.parse(await readFile(join(outputDir, 'queries.json'), 'utf8'));
const ids = process.argv.slice(2);
if (!ids.length || ids.some(id => !queries.some(q => q.id === id))) {
  throw new Error('Pass one or more IDs present in queries.json.');
}
ensureChromiumExecutableEnv();
const flags = parseFlags([]);
const config = loadConfig(flags, process.env, localPackage);
const pool = resolvePool(config, resolvePoolName(flags, process.env, config), process.env, localPackage);
const dispatcher = createDaemonDispatcher(pool, join(localPackage, 'dist/daemon/main.js'), [`--pool=${pool.name}`]);

await Promise.all(ids.map(async id => {
  const query = queries.find(q => q.id === id);
  const record = { id, method: query.method, query: query.query, startedAt: new Date().toISOString(), transport: 'local perplexity-web-mcp daemon client via shell', status: 'running' };
  console.log(JSON.stringify({ id, status: 'started', method: query.method }));
  try {
    record.result = await dispatcher[query.method](query.query);
    record.status = 'completed';
  } catch (error) {
    record.status = 'failed';
    record.error = { name: error.name, code: error.code, message: error.message };
  }
  record.finishedAt = new Date().toISOString();
  await writeFile(join(outputDir, `${id}.json`), JSON.stringify(record, null, 2) + '\n');
  const answer = record.result?.answer ?? '';
  const sources = record.result?.sources ?? [];
  const body = `# ${id}\n\n- Status: ${record.status}\n- Method: ${record.method}\n- Started (UTC): ${record.startedAt}\n- Finished (UTC): ${record.finishedAt}\n- Transport: ${record.transport}\n\n## Question\n\n${record.query}\n\n## Unverified Perplexity answer\n\n${answer || JSON.stringify(record.error)}\n\n## Sources returned by Perplexity (not automatically verified)\n\n${sources.map((source, index) => `${index + 1}. [${source.title}](${source.url})`).join('\n')}\n`;
  await writeFile(join(outputDir, `${id}.md`), body);
  console.log(JSON.stringify({ id, status: record.status, answerCharacters: answer.length, sourceCount: sources.length, error: record.error }));
}));
