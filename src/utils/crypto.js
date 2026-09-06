import crypto from 'node:crypto';

// Secret key for JWT and system-level crypto
export const JWT_SECRET = process.env.JWT_SECRET || 'nexus-academic-hyper-secure-secret-key-2026-v3';
export const JWT_EXPIRES_IN_SECONDS = 86400; // 24 hours

// ================= PASSWORD HASHING (Scrypt + Salt) =================
export function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const derivedKey = crypto.scryptSync(password, salt, 64);
  return `${salt}:${derivedKey.toString('hex')}`;
}

export function verifyPassword(password, storedHash) {
  try {
    if (!storedHash || !storedHash.includes(':')) {
      // Fallback for bcrypt hashes if seeded with bcrypt
      return false;
    }
    const [salt, key] = storedHash.split(':');
    const keyBuffer = Buffer.from(key, 'hex');
    const derivedKey = crypto.scryptSync(password, salt, 64);
    return crypto.timingSafeEqual(keyBuffer, derivedKey);
  } catch (err) {
    return false;
  }
}

// ================= JWT TOKEN GENERATION & VERIFICATION =================
function base64UrlEncode(str) {
  return Buffer.from(str)
    .toString('base64')
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');
}

function base64UrlDecode(str) {
  str = str.replace(/-/g, '+').replace(/_/g, '/');
  while (str.length % 4) str += '=';
  return Buffer.from(str, 'base64').toString('utf-8');
}

export function signToken(payload, expiresInSeconds = JWT_EXPIRES_IN_SECONDS) {
  const header = { alg: 'HS256', typ: 'JWT' };
  const now = Math.floor(Date.now() / 1000);
  const fullPayload = {
    ...payload,
    iat: now,
    exp: now + expiresInSeconds
  };

  const encodedHeader = base64UrlEncode(JSON.stringify(header));
  const encodedPayload = base64UrlEncode(JSON.stringify(fullPayload));
  const signature = crypto
    .createHmac('sha256', JWT_SECRET)
    .update(`${encodedHeader}.${encodedPayload}`)
    .digest('base64')
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');

  return `${encodedHeader}.${encodedPayload}.${signature}`;
}

export function verifyToken(token) {
  try {
    if (!token || typeof token !== 'string') return null;
    const parts = token.split('.');
    if (parts.length !== 3) return null;

    const [encodedHeader, encodedPayload, signature] = parts;
    const expectedSignature = crypto
      .createHmac('sha256', JWT_SECRET)
      .update(`${encodedHeader}.${encodedPayload}`)
      .digest('base64')
      .replace(/=/g, '')
      .replace(/\+/g, '-')
      .replace(/\//g, '_');

    const sigA = Buffer.from(signature);
    const sigB = Buffer.from(expectedSignature);
    if (sigA.length !== sigB.length || !crypto.timingSafeEqual(sigA, sigB)) {
      return null;
    }

    const payload = JSON.parse(base64UrlDecode(encodedPayload));
    const now = Math.floor(Date.now() / 1000);
    if (payload.exp && payload.exp < now) {
      return null; // Expired
    }

    return payload;
  } catch (err) {
    return null;
  }
}

// ================= DYNAMIC QR HMAC SIGNATURE & ROTATION =================
export const QR_ROTATION_INTERVAL_SECONDS = 20; // 20-second dynamic TOTP-style rotation
export const QR_TOLERANCE_SECONDS = 35; // Max validity window to prevent replay of old frames

export function generateQRPayload(sessionId, sessionSecret) {
  const timestamp = Date.now();
  const nonce = crypto.randomBytes(8).toString('hex');
  
  // Data string for signing: sessionId:nonce:timestamp
  const message = `${sessionId}:${nonce}:${timestamp}`;
  const signature = crypto
    .createHmac('sha256', sessionSecret)
    .update(message)
    .digest('hex');

  return {
    sessionId: Number(sessionId),
    nonce,
    timestamp,
    signature,
    expiresIn: QR_ROTATION_INTERVAL_SECONDS
  };
}

export function verifyQRPayload(sessionId, nonce, timestamp, signature, sessionSecret) {
  const ts = Number(timestamp);
  const now = Date.now();
  const ageSeconds = Math.abs(now - ts) / 1000;

  // 1. Time window validation
  if (ageSeconds > QR_TOLERANCE_SECONDS) {
    return { valid: false, reason: 'TOKEN_EXPIRED', ageSeconds };
  }

  // 2. Cryptographic signature validation
  const message = `${sessionId}:${nonce}:${timestamp}`;
  const expectedSignature = crypto
    .createHmac('sha256', sessionSecret)
    .update(message)
    .digest('hex');

  const sigA = Buffer.from(signature, 'hex');
  const sigB = Buffer.from(expectedSignature, 'hex');

  if (sigA.length !== sigB.length || !crypto.timingSafeEqual(sigA, sigB)) {
    return { valid: false, reason: 'INVALID_SIGNATURE' };
  }

  return { valid: true, ageSeconds };
}

// ================= HAVERSINE DISTANCE CALCULATOR (GEOPRESENCE) =================
export function calculateHaversineDistance(lat1, lon1, lat2, lon2) {
  if (lat1 == null || lon1 == null || lat2 == null || lon2 == null) return null;
  const R = 6371000; // Earth radius in meters
  const dLat = (lat2 - lat1) * (Math.PI / 180);
  const dLon = (lon2 - lon1) * (Math.PI / 180);
  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1 * (Math.PI / 180)) *
      Math.cos(lat2 * (Math.PI / 180)) *
      Math.sin(dLon / 2) *
      Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return Math.round(R * c * 10) / 10; // Rounded to 1 decimal place (meters)
}
