import process from 'node:process'
import path from 'node:path'
import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const nodeBinary = process.execPath
const children = [
  {
    name: 'messenger-sync-up',
    script: path.join(rootDir, 'scripts', 'firebase-messenger-stream.mjs'),
    args: [],
  },
]

let shuttingDown = false
const running = new Map()

function writeLine(level, childName, message) {
  const output = String(message || '').trim()
  if (output === '') return
  process.stdout.write(JSON.stringify({ service: 'firebase-sync', child: childName, level, message: output }) + '\n')
}

function startChild(spec) {
  if (shuttingDown || running.has(spec.name)) return
  const child = spawn(nodeBinary, [spec.script, ...spec.args], {
    cwd: rootDir,
    env: process.env,
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  running.set(spec.name, child)
  writeLine('info', spec.name, `started pid=${child.pid}`)
  child.stdout.on('data', (chunk) => writeLine('info', spec.name, chunk))
  child.stderr.on('data', (chunk) => writeLine('error', spec.name, chunk))
  child.on('error', (error) => writeLine('error', spec.name, error.message))
  child.on('exit', (code, signal) => {
    running.delete(spec.name)
    writeLine('warn', spec.name, `stopped code=${code ?? 'null'} signal=${signal ?? 'none'}`)
    if (!shuttingDown) setTimeout(() => startChild(spec), 2000)
  })
}

async function shutdown(signal) {
  if (shuttingDown) return
  shuttingDown = true
  writeLine('info', 'supervisor', `stopping after ${signal}`)
  for (const child of running.values()) child.kill('SIGTERM')
  await new Promise((resolve) => setTimeout(resolve, 5000))
  for (const child of running.values()) child.kill('SIGKILL')
  process.exit(0)
}

process.on('SIGINT', () => void shutdown('SIGINT'))
process.on('SIGTERM', () => void shutdown('SIGTERM'))

process.stdout.write(JSON.stringify({
  ok: true,
  status: 'firebase_sync_supervisor_started',
  children: children.map(({ name }) => name),
}) + '\n')
children.forEach(startChild)
