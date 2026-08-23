const vscode = require('vscode')
const http = require('node:http')
const path = require('node:path')
const fs = require('node:fs')
const os = require('node:os')
const { spawn } = require('node:child_process')

const extensionVersion = '2.0.4'
const bridgeHost = '127.0.0.1'
const bridgePort = 43127
const maxRequestBytes = 64 * 1024
let bridgeServer = null

function currentWorkspace() {
  return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath || ''
}

function canonicalWorkspace(workspacePath) {
  const resolved = path.resolve(String(workspacePath || ''))
  try {
    return fs.realpathSync(resolved)
  } catch {
    return resolved
  }
}

function workspacePathsMatch(left, right) {
  return canonicalWorkspace(left) === canonicalWorkspace(right)
}

function validJobKey(value) {
  return /^[0-9a-f-]{36}$/.test(String(value || ''))
}

function helperPath(root) {
  return path.join(root, 'tools', 'builderx-ai-job.php')
}

function ensureCodexHelperRules() {
  const rulesDirectory = path.join(os.homedir(), '.codex', 'rules')
  const rulesPath = path.join(rulesDirectory, 'builderx.rules')
  const requiredRules = [
    'prefix_rule(pattern=["php", "tools/builderx-ai-job.php", "complete"], decision="allow")',
    'prefix_rule(pattern=["php", "tools/builderx-ai-job.php", "fail"], decision="allow")',
  ]
  fs.mkdirSync(rulesDirectory, { recursive: true, mode: 0o700 })
  const existing = fs.existsSync(rulesPath) ? fs.readFileSync(rulesPath, 'utf8') : ''
  const missing = requiredRules.filter((rule) => !existing.includes(rule))
  if (missing.length > 0) {
    const header = existing.trim() === ''
      ? '# BuilderX owner-approved MySQL job completion helpers.\n'
      : '\n# BuilderX owner-approved MySQL job completion helpers.\n'
    const next = `${existing.trimEnd()}${header}${missing.join('\n')}\n`
    const temporaryPath = `${rulesPath}.${process.pid}.${Date.now()}.tmp`
    fs.writeFileSync(temporaryPath, next, { encoding: 'utf8', mode: 0o600 })
    fs.renameSync(temporaryPath, rulesPath)
  }
  fs.chmodSync(rulesPath, 0o600)
  const verified = fs.readFileSync(rulesPath, 'utf8')
  if (!requiredRules.every((rule) => verified.includes(rule))) {
    throw new Error('The BuilderX Codex helper approval rules could not be verified.')
  }
  return { ready: true, path: rulesPath }
}

function runDesktopCommand(command, args, root, label, acceptedExitCodes = [0]) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] })
    let stdout = ''
    let stderr = ''
    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString()
      if (stdout.length > 1024 * 1024) child.kill()
    })
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString()
      if (stderr.length > 1024 * 1024) child.kill()
    })
    child.on('error', () => reject(new Error(`${label} could not start.`)))
    child.on('close', (code) => {
      if (!acceptedExitCodes.includes(code)) {
        reject(new Error(`${label} failed${stderr.trim() ? `: ${stderr.trim()}` : '.'}`))
        return
      }
      resolve(stdout.trim())
    })
  })
}

async function ensureDesktopGitRepository(root) {
  const gitMetadata = path.join(root, '.git')
  const created = !fs.existsSync(gitMetadata)
  if (created) {
    await runDesktopCommand('git', ['init', '--template=', '-b', 'main'], root, 'Desktop Git initialization')
  }
  const safeRoot = canonicalWorkspace(root)
  const configuredSafeDirectories = await runDesktopCommand(
    'git',
    ['config', '--global', '--get-all', 'safe.directory'],
    root,
    'Desktop Git safe-directory query',
    [0, 1],
  )
  const safeDirectoryReady = configuredSafeDirectories
    .split(/\r?\n/)
    .filter(Boolean)
    .some((configuredRoot) => configuredRoot === '*' || workspacePathsMatch(configuredRoot, safeRoot))
  if (!safeDirectoryReady) {
    await runDesktopCommand(
      'git',
      ['config', '--global', '--add', 'safe.directory', safeRoot],
      root,
      'Desktop Git safe-directory registration',
    )
  }
  const existingHead = await runDesktopCommand(
    'git',
    ['rev-parse', '--verify', 'HEAD'],
    root,
    'Desktop Git HEAD verification',
    [0, 128],
  )
  const initialCommitCreated = existingHead === ''
  if (initialCommitCreated) {
    await runDesktopCommand('git', ['add', '--all'], root, 'Desktop Git baseline staging')
    await runDesktopCommand(
      'git',
      [
        '-c',
        'user.name=BuilderX',
        '-c',
        'user.email=builderx@localhost',
        'commit',
        '--no-gpg-sign',
        '--allow-empty',
        '-m',
        'Initialize BuilderX workspace',
      ],
      root,
      'Desktop Git baseline commit',
    )
  }
  const topLevel = await runDesktopCommand('git', ['rev-parse', '--show-toplevel'], root, 'Desktop Git verification')
  if (!workspacePathsMatch(topLevel, root)) {
    throw new Error('The active BuilderX folder is not the root of its local Git repository.')
  }
  if (created || initialCommitCreated) {
    const commands = await vscode.commands.getCommands(true)
    if (commands.includes('git.refresh')) await vscode.commands.executeCommand('git.refresh')
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
  return { created, initialCommitCreated, topLevel: canonicalWorkspace(topLevel) }
}

function runHelper(root, args, input = '') {
  return new Promise((resolve, reject) => {
    const helper = helperPath(root)
    if (!fs.existsSync(helper)) {
      reject(new Error('This workspace does not contain the BuilderX MySQL job helper.'))
      return
    }
    const child = spawn('php', [helper, ...args], { cwd: root, stdio: ['pipe', 'pipe', 'pipe'] })
    let stdout = ''
    let stderr = ''
    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString()
      if (stdout.length > 16 * 1024 * 1024) child.kill()
    })
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString()
      if (stderr.length > 1024 * 1024) child.kill()
    })
    child.on('error', reject)
    child.on('close', (code) => {
      let payload
      try {
        payload = JSON.parse((code === 0 ? stdout : stderr || stdout).trim())
      } catch {
        reject(new Error('The BuilderX MySQL job helper returned invalid JSON.'))
        return
      }
      if (code !== 0 || payload?.ok !== true) {
        reject(new Error(String(payload?.message || 'The BuilderX MySQL job helper failed.')))
        return
      }
      resolve(payload)
    })
    child.stdin.end(input)
  })
}

async function codexReadiness() {
  const commands = await vscode.commands.getCommands(true)
  const directCommand = ['chatgpt.sendMessage', 'chatgpt.sendText', 'chatgpt.sendPrompt'].find((command) => commands.includes(command)) || ''
  const legacyCommand = commands.includes('chatgpt.implementTodo')
  return {
    ready: directCommand !== '' || legacyCommand,
    directCommand,
    legacyCommand,
    deliveryMode: directCommand !== '' ? 'direct-text' : legacyCommand ? 'implement-todo-wrapper' : 'unavailable',
  }
}

async function sendToVisibleCodex(root, prompt) {
  const readiness = await codexReadiness()
  if (!readiness.ready) throw new Error('The OpenAI Codex VS Code extension is not active.')
  const commands = await vscode.commands.getCommands(true)
  if (commands.includes('chatgpt.openSidebar')) await vscode.commands.executeCommand('chatgpt.openSidebar')
  if (readiness.directCommand) {
    await vscode.commands.executeCommand(readiness.directCommand, prompt)
  } else {
    await vscode.commands.executeCommand('chatgpt.implementTodo', {
      cwd: root,
      fileName: helperPath(root),
      line: 1,
      comment: prompt,
    })
  }
  return readiness
}

function sendJson(response, status, payload) {
  const body = JSON.stringify(payload)
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'Content-Length': Buffer.byteLength(body) })
  response.end(body)
}

function readJson(request) {
  return new Promise((resolve, reject) => {
    let body = ''
    request.on('data', (chunk) => {
      body += chunk.toString()
      if (body.length > maxRequestBytes) {
        reject(new Error('The BuilderX bridge request is too large.'))
        request.destroy()
      }
    })
    request.on('end', () => {
      try {
        const payload = body === '' ? {} : JSON.parse(body)
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('The BuilderX bridge request must be a JSON object.')
        resolve(payload)
      } catch (error) {
        reject(error)
      }
    })
    request.on('error', reject)
  })
}

async function health(workspaceRoot = '') {
  const root = currentWorkspace()
  if (!root) throw new Error('Open a BuilderX project folder in VS Code.')
  if (workspaceRoot && !workspacePathsMatch(workspaceRoot, root)) throw new Error('BuilderX is open in a different workspace than this project.')
  const git = await ensureDesktopGitRepository(root)
  const codexRules = ensureCodexHelperRules()
  const readiness = await codexReadiness()
  const database = await runHelper(root, ['health'])
  return {
    ok: true,
    bridge: 'BuilderX',
    version: extensionVersion,
    companion_extension_version: extensionVersion,
    workspace: canonicalWorkspace(root),
    transport: 'mysql',
    target: 'Visible VS Code Codex Chat',
    companion_extension_installed: true,
    builderx_extension_active: true,
    extension_workspace_ready: true,
    extension_version_ready: true,
    code_ready: readiness.ready,
    codex_command_ready: readiness.ready,
    direct_text_send_ready: readiness.directCommand !== '',
    legacy_wrapper_available: readiness.legacyCommand,
    context_ready: database.context_table_ready === true,
    ready_to_send: readiness.ready && database.job_table_ready === true && database.context_table_ready === true,
    active_thread_ready: readiness.ready,
    active_thread_busy: false,
    active_thread_id: null,
    extension_probe_state: readiness.ready ? 'ready' : 'not_ready',
    extension_probe_message: readiness.ready ? 'The visible Codex Chat and MySQL transport are ready.' : 'The OpenAI Codex send command is not active.',
    event_stream: true,
    delivery_mode: readiness.deliveryMode,
    desktop_git_ready: true,
    desktop_git_created: git.created,
    desktop_git_initial_commit_created: git.initialCommitCreated,
    desktop_git_safe_directory: true,
    codex_helper_rule_ready: codexRules.ready,
  }
}

async function handoff(payload) {
  const root = currentWorkspace()
  if (!root) throw new Error('Open a BuilderX project folder in VS Code.')
  if (!workspacePathsMatch(String(payload.workspace_root || ''), root)) throw new Error('BuilderX is open in a different workspace than this project.')
  await ensureDesktopGitRepository(root)
  ensureCodexHelperRules()
  const jobKey = String(payload.job_key || '').toLowerCase()
  if (!validJobKey(jobKey)) throw new Error('BuilderX received an invalid MySQL job key.')
  const claimed = await runHelper(root, ['claim', jobKey])
  try {
    const readiness = await sendToVisibleCodex(root, String(claimed.prompt || ''))
    return {
      ok: true,
      bridge: 'BuilderX',
      model_key: null,
      delivery: {
        request_id: jobKey,
        job_key: jobKey,
        acknowledged: true,
        state: 'submitted',
        storage: 'mysql',
        workspace: canonicalWorkspace(root),
        delivery_mode: readiness.deliveryMode,
      },
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    await runHelper(root, ['fail', jobKey], message).catch(() => undefined)
    throw error
  }
}

async function handleHttp(request, response) {
  try {
    const url = new URL(request.url || '/', `http://${bridgeHost}:${bridgePort}`)
    if (request.method === 'GET' && url.pathname === '/health') {
      sendJson(response, 200, await health(url.searchParams.get('workspace_root') || ''))
      return
    }
    if (request.method === 'GET' && url.pathname === '/capabilities') {
      sendJson(response, 200, { ok: true, bridge: 'BuilderX', version: extensionVersion, transport: 'mysql', parallel_execution: { supported: false, task_channels: 1 } })
      return
    }
    if (request.method === 'POST' && ['/handoff', '/handoff-result'].includes(url.pathname)) {
      sendJson(response, 200, await handoff(await readJson(request)))
      return
    }
    if (request.method === 'POST' && url.pathname === '/restart') {
      const payload = await readJson(request)
      sendJson(response, 200, { ...(await health(String(payload.workspace_root || ''))), restarted: false, message: 'The global companion follows the active workspace automatically.' })
      return
    }
    sendJson(response, 404, { ok: false, message: 'The BuilderX companion route was not found.' })
  } catch (error) {
    sendJson(response, 422, { ok: false, message: error instanceof Error ? error.message : String(error) })
  }
}

async function sendManualPrompt() {
  const root = currentWorkspace()
  if (!root) {
    void vscode.window.showErrorMessage('Open a BuilderX project folder first.')
    return
  }
  const prompt = await vscode.window.showInputBox({ title: 'BuilderX → Codex Chat', prompt: 'Message to send to the visible Codex Chat', ignoreFocusOut: true })
  if (!prompt?.trim()) return
  await sendToVisibleCodex(root, prompt.trim())
}

function activate(context) {
  ensureCodexHelperRules()
  bridgeServer = http.createServer((request, response) => { void handleHttp(request, response) })
  bridgeServer.on('error', (error) => { void vscode.window.showErrorMessage(`BuilderX companion could not start: ${error.message}`) })
  bridgeServer.listen(bridgePort, bridgeHost)
  context.subscriptions.push(
    { dispose: () => bridgeServer?.close() },
    vscode.commands.registerCommand('builderx.sendToCodex', sendManualPrompt),
  )
}

function deactivate() {
  bridgeServer?.close()
  bridgeServer = null
}

module.exports = { activate, deactivate, canonicalWorkspace, workspacePathsMatch, ensureDesktopGitRepository, ensureCodexHelperRules }
