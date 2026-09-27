const encoder = new TextEncoder();
export const PASSWORD_HASH_ITERATIONS = 310_000;
export const PASSWORD_MIN_LENGTH = 12;
export const PASSWORD_MAX_LENGTH = 256;

function bytesToBase64(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary);
}
function base64ToBytes(value: string): Uint8Array {
  return Uint8Array.from(atob(value), (char) => char.charCodeAt(0));
}
export function randomBytes(length: number): Uint8Array<ArrayBuffer> {
  const bytes = new Uint8Array(new ArrayBuffer(length));
  crypto.getRandomValues(bytes);
  return bytes;
}
function arrayBuffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.slice().buffer as ArrayBuffer;
}
function constantTimeEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.byteLength !== b.byteLength) return false;
  let difference = 0;
  for (let index = 0; index < a.byteLength; index++) difference |= a[index] ^ b[index];
  return difference === 0;
}
export function randomToken(length = 32): string {
  return bytesToBase64(randomBytes(length)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
}
export async function sha256(value: string): Promise<string> {
  return bytesToBase64(new Uint8Array(await crypto.subtle.digest("SHA-256", arrayBuffer(encoder.encode(value)))));
}
export function validPassword(password: string): boolean {
  return password.length >= PASSWORD_MIN_LENGTH && password.length <= PASSWORD_MAX_LENGTH;
}
export function passwordNeedsRehash(stored: string): boolean {
  const parts = stored.split("$");
  return parts.length !== 5 || parts[0] !== "pbkdf2" || parts[1] !== "sha256" || Number(parts[2]) !== PASSWORD_HASH_ITERATIONS;
}
export async function hashPassword(password: string): Promise<string> {
  if (!validPassword(password)) throw new Error("Invalid password length.");
  const salt = randomBytes(16);
  const key = await crypto.subtle.importKey("raw", arrayBuffer(encoder.encode(password)), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", salt: arrayBuffer(salt), iterations: PASSWORD_HASH_ITERATIONS, hash: "SHA-256" }, key, 256,
  );
  return ["pbkdf2","sha256",String(PASSWORD_HASH_ITERATIONS),bytesToBase64(salt),bytesToBase64(new Uint8Array(bits))].join("$");
}
export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const parts = stored.split("$");
  if (parts.length !== 5 || parts[0] !== "pbkdf2" || parts[1] !== "sha256") return false;
  const iterations = Number(parts[2]);
  if (!Number.isSafeInteger(iterations) || iterations < 100_000 || iterations > 2_000_000) return false;
  let salt: Uint8Array, expected: Uint8Array;
  try { salt = base64ToBytes(parts[3]); expected = base64ToBytes(parts[4]); } catch { return false; }
  const key = await crypto.subtle.importKey("raw", arrayBuffer(encoder.encode(password)), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", salt: arrayBuffer(salt), iterations, hash: "SHA-256" }, key, expected.length * 8);
  const actual = new Uint8Array(bits);
  return constantTimeEqual(actual, expected);
}
export function json<T>(value: T, status = 200, headers?: HeadersInit): Response {
  return new Response(JSON.stringify(value), { status, headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers } });
}
export function text(value: string, status = 200, headers?: HeadersInit): Response {
  return new Response(value, { status, headers: { "content-type": "text/plain; charset=utf-8", ...headers } });
}
export function parseCookies(request: Request): Record<string, string> {
  const header = request.headers.get("cookie") ?? "";
  return Object.fromEntries(header.split(";").map((part) => part.trim()).filter(Boolean).map((part) => {
    const index = part.indexOf("=");
    return index === -1 ? [part, ""] : [part.slice(0,index), decodeURIComponent(part.slice(index+1))];
  }));
}
export function sessionCookie(token: string, maxAgeSeconds: number): string {
  return [`devone_session=${encodeURIComponent(token)}`, "Path=/", `Max-Age=${Math.max(0,Math.floor(maxAgeSeconds))}`, "HttpOnly", "Secure", "SameSite=Lax"].join("; ");
}
export const clearSessionCookie = "devone_session=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax";
