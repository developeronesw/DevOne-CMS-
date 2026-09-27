const encoder = new TextEncoder();

function toBase64Url(bytes: Uint8Array): string {
  let value = "";
  for (const byte of bytes) value += String.fromCharCode(byte);
  return btoa(value).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
}

function fromBase64Url(value: string): Uint8Array {
  const normalized = value.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (value.length % 4)) % 4);
  return Uint8Array.from(atob(normalized), char => char.charCodeAt(0));
}

async function keyFromSecret(secret: string): Promise<CryptoKey> {
  const raw = fromBase64Url(secret);
  if (raw.byteLength !== 32) throw new Error("DevOne secret key must be 32 bytes.");
  return crypto.subtle.importKey("raw", raw, { name: "AES-GCM" }, false, ["encrypt", "decrypt"]);
}

export async function encryptSecret(plaintext: string, secret: string): Promise<string> {
  if (!secret) throw new Error("Secret encryption key is not configured.");
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await keyFromSecret(secret);
  const ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, encoder.encode(plaintext)));
  const packed = new Uint8Array(iv.length + ciphertext.length);
  packed.set(iv, 0);
  packed.set(ciphertext, iv.length);
  return toBase64Url(packed);
}

export async function decryptSecret(value: string, secret: string): Promise<string> {
  if (!secret) throw new Error("Secret encryption key is not configured.");
  const packed = fromBase64Url(value);
  if (packed.byteLength < 13) throw new Error("Invalid encrypted secret.");
  const key = await keyFromSecret(secret);
  const plaintext = await crypto.subtle.decrypt({ name: "AES-GCM", iv: packed.slice(0, 12) }, key, packed.slice(12));
  return new TextDecoder().decode(plaintext);
}
