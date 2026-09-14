const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });
const crypto = require('crypto');
const express = require('express');
const helmet = require('helmet');
const cors = require('cors');

const app = express();

app.use(helmet({
  contentSecurityPolicy: false
}));
app.use(cors({
  origin: (origin, cb) => cb(null, true),
  credentials: true
}));
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: false }));

app.get('/', (req, res) => res.json({
  status: 'ok',
  service: 'LEXSHIELD API',
  endpoints: ['/health', '/api/phishing/check', '/api/video/status', '/api/video/jaas-token']
}));

app.get('/health', (req, res) => res.json({ status: 'ok' }));

function envValue(name, fallback = '') {
  return String(process.env[name] || fallback).trim();
}

function base64UrlEncode(value) {
  return Buffer.from(value).toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

function jaasPrivateKey() {
  const encoded = envValue('LEX_VIDEO_JAAS_PRIVATE_KEY_B64');
  if (encoded) {
    try {
      const decoded = Buffer.from(encoded, 'base64').toString('utf8').trim();
      if (decoded) return decoded;
    } catch (error) {
      return '';
    }
  }

  const raw = envValue('LEX_VIDEO_JAAS_PRIVATE_KEY');
  return raw ? raw.replace(/\\n/g, '\n') : '';
}

function jaasStatus() {
  const privateKey = jaasPrivateKey();
  return {
    provider: envValue('LEX_VIDEO_PROVIDER', 'jaas'),
    domain: envValue('LEX_VIDEO_JAAS_DOMAIN', '8x8.vc'),
    app_id_configured: envValue('LEX_VIDEO_JAAS_APP_ID') !== '',
    key_id_configured: envValue('LEX_VIDEO_JAAS_KEY_ID') !== '',
    private_key_configured: privateKey !== '',
    private_key_looks_valid: privateKey.includes('BEGIN PRIVATE KEY'),
    token_secret_configured: envValue('LEX_VIDEO_TOKEN_SECRET') !== '',
  };
}

function signJaasJwt({ meeting, user, expiresAt }) {
  const appId = envValue('LEX_VIDEO_JAAS_APP_ID');
  const keyId = envValue('LEX_VIDEO_JAAS_KEY_ID');
  const privateKey = jaasPrivateKey();
  if (!appId || !keyId || !privateKey) {
    throw new Error('JaaS is not configured on the API service.');
  }

  const now = Math.floor(Date.now() / 1000);
  const exp = Math.max(now + 60, Math.min(Number(expiresAt || 0) || now + 3600, now + 3600));
  const room = String(meeting?.room || '').trim();
  if (!/^[A-Za-z0-9_-]{8,180}$/.test(room)) {
    throw new Error('Invalid JaaS room.');
  }

  const header = {
    alg: 'RS256',
    kid: keyId,
    typ: 'JWT',
  };
  const payload = {
    aud: 'jitsi',
    exp,
    iss: 'chat',
    nbf: now - 30,
    room,
    sub: appId,
    context: {
      user: {
        id: String(user?.id || ''),
        name: String(user?.name || 'LEXSHIELD User').trim() || 'LEXSHIELD User',
        avatar: '',
        email: String(user?.email || '').trim(),
        moderator: user?.role === 'lawyer' ? 'true' : 'false',
      },
      features: {
        livestreaming: false,
        recording: false,
        transcription: false,
        'outbound-call': false,
      },
      room: {
        regex: false,
      },
    },
  };

  const signingInput = `${base64UrlEncode(JSON.stringify(header))}.${base64UrlEncode(JSON.stringify(payload))}`;
  const signer = crypto.createSign('RSA-SHA256');
  signer.update(signingInput);
  signer.end();
  return `${signingInput}.${base64UrlEncode(signer.sign(privateKey))}`;
}

function verifyLexshieldSignature(req) {
  const secret = envValue('LEX_VIDEO_TOKEN_SECRET');
  if (!secret) {
    return { ok: false, message: 'Video token secret is not configured on the API service.' };
  }
  const signature = String(req.get('X-Lexshield-Signature') || '');
  const timestamp = String(req.get('X-Lexshield-Timestamp') || '');
  if (!/^\d{10}$/.test(timestamp)) {
    return { ok: false, message: 'Missing video token timestamp.' };
  }
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - Number(timestamp)) > 300) {
    return { ok: false, message: 'Expired video token request.' };
  }
  const body = JSON.stringify(req.body || {});
  const expected = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${body}`)
    .digest('hex');
  const expectedBuffer = Buffer.from(expected, 'hex');
  const actualBuffer = Buffer.from(signature, 'hex');
  if (actualBuffer.length !== expectedBuffer.length || !crypto.timingSafeEqual(actualBuffer, expectedBuffer)) {
    return { ok: false, message: 'Invalid video token signature.' };
  }
  return { ok: true };
}

app.post('/api/video/jaas-token', (req, res) => {
  const auth = verifyLexshieldSignature(req);
  if (!auth.ok) {
    return res.status(401).json({ ok: false, message: auth.message });
  }

  try {
    const jwt = signJaasJwt(req.body || {});
    return res.json({
      ok: true,
      jwt,
      app_id: envValue('LEX_VIDEO_JAAS_APP_ID'),
      domain: envValue('LEX_VIDEO_JAAS_DOMAIN', '8x8.vc'),
    });
  } catch (error) {
    console.error('[VIDEO] JaaS token generation failed:', error.message);
    return res.status(500).json({ ok: false, message: 'Unable to create the video token.' });
  }
});

app.get('/api/video/status', (req, res) => res.json({
  ok: true,
  jaas: jaasStatus(),
}));

const suspiciousTlds = new Set(['zip', 'mov', 'click', 'country', 'gq', 'tk', 'ml', 'cf', 'example', 'invalid', 'test', 'localhost']);
const brandTerms = ['paypal', 'google', 'microsoft', 'facebook', 'apple', 'gcash', 'bank', 'lexshield', 'netflix'];
const lookalikeBrands = ['paypal', 'google', 'microsoft', 'facebook', 'apple', 'gcash', 'lexshield', 'netflix'];
const trustedDomains = new Set(['paypal.com', 'google.com', 'microsoft.com', 'facebook.com', 'apple.com', 'gcash.com', 'lexshield.com', 'netflix.com']);
const financialTerms = ['bank', 'billing', 'invoice', 'payment', 'pay', 'wallet', 'gcash', 'paypal', 'card', 'credit', 'loan'];
const riskyTerms = ['login', 'verify', 'password', 'update', 'secure', 'account', 'wallet', 'payment', 'confirm', 'unlock', 'suspend', 'limited'];
const urlShorteners = new Set(['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly', 'rebrand.ly', 'shorturl.at']);

function confusableSkeleton(value) {
  return value.toLowerCase().replace(/[0134578i]/g, (char) => ({
    0: 'o',
    1: 'l',
    3: 'e',
    4: 'a',
    5: 's',
    7: 't',
    8: 'b',
    i: 'l',
  }[char] || char));
}

function levenshteinDistance(a, b) {
  const previous = Array.from({ length: b.length + 1 }, (_, index) => index);
  for (let i = 1; i <= a.length; i += 1) {
    const current = [i];
    for (let j = 1; j <= b.length; j += 1) {
      current[j] = Math.min(
        current[j - 1] + 1,
        previous[j] + 1,
        previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)
      );
    }
    previous.splice(0, previous.length, ...current);
  }
  return previous[b.length];
}

function lookalikeBrand(hostname) {
  if ([...trustedDomains].some((domain) => hostname === domain || hostname.endsWith(`.${domain}`))) {
    return '';
  }

  const labels = hostname.split('.');
  labels.pop();
  const candidates = new Set();
  labels.forEach((label) => {
    label.split(/[^a-z0-9]+/).filter(Boolean).forEach((token) => candidates.add(token));
    candidates.add(label.replace(/[^a-z0-9]/g, ''));
  });

  for (const candidate of candidates) {
    const skeleton = confusableSkeleton(candidate);
    for (const brand of lookalikeBrands) {
      if (candidate === brand) continue;
      const brandSkeleton = confusableSkeleton(brand);
      const limit = brand.length <= 6 ? 1 : 2;
      if (skeleton === brand || levenshteinDistance(skeleton, brandSkeleton) <= limit) {
        return brand;
      }
    }
  }

  return '';
}

async function resolveRedirects(rawUrl) {
  const chain = [rawUrl];
  let currentUrl = rawUrl;
  let redirectError = '';
  const maxRedirects = 5;

  for (let index = 0; index < maxRedirects; index += 1) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch(currentUrl, {
        method: 'HEAD',
        redirect: 'manual',
        signal: controller.signal,
        headers: {
          'User-Agent': 'LEXSHIELD-Phishing-Detector/1.0',
        },
      });
      const location = response.headers.get('location');
      if (response.status < 300 || response.status >= 400 || !location) {
        break;
      }

      const nextUrl = new URL(location, currentUrl).href;
      if (chain.includes(nextUrl)) {
        redirectError = 'The URL has a redirect loop.';
        break;
      }
      chain.push(nextUrl);
      currentUrl = nextUrl;
    } catch (error) {
      redirectError = 'Unable to resolve redirects for this URL.';
      break;
    } finally {
      clearTimeout(timeout);
    }
  }

  if (chain.length > maxRedirects && !redirectError) {
    redirectError = 'The URL has too many redirects.';
  }

  return {
    finalUrl: currentUrl,
    redirectCount: Math.max(0, chain.length - 1),
    redirectChain: chain,
    redirectError,
  };
}

function evaluateSingleUrl(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch (error) {
    return { error: 'Enter a valid URL.' };
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return { error: 'Only http and https URLs can be scanned.' };
  }

  const hostname = parsed.hostname.toLowerCase();
  const fullUrl = parsed.href.toLowerCase();
  const labels = hostname.split('.');
  const tld = labels[labels.length - 1] || '';
  let risk = 0;
  const findings = [];

  if (parsed.protocol === 'http:') {
    risk += 28;
    findings.push('The URL does not use HTTPS.');
  }
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(hostname)) {
    risk += 28;
    findings.push('The host uses a raw IP address.');
  }
  if (hostname.includes('xn--')) {
    risk += 18;
    findings.push('The domain contains punycode characters.');
  }
  if (hostname.length > 45 || labels.length > 4) {
    risk += 12;
    findings.push('The domain is unusually long or deeply nested.');
  }
  if (suspiciousTlds.has(tld)) {
    risk += 18;
    findings.push(tld === 'example'
      ? 'The .example domain is reserved for examples, not real account activity.'
      : 'The top-level domain is commonly abused in scams.');
  }
  if (/[_.-]{2,}/.test(hostname) || hostname.split('-').length > 3) {
    risk += 10;
    findings.push('The domain uses unusual separator patterns.');
  }
  if (urlShorteners.has(hostname)) {
    risk += 18;
    findings.push('The URL uses a link shortener that hides the final destination.');
  }
  if (parsed.href.includes('@')) {
    risk += 22;
    findings.push('The URL contains an @ symbol, which can hide the real destination.');
  }
  const suspiciousBrand = lookalikeBrand(hostname);
  if (suspiciousBrand) {
    risk += 26;
    findings.push(`The domain looks like an impersonation of ${suspiciousBrand.charAt(0).toUpperCase()}${suspiciousBrand.slice(1)}.`);
  }
  const hasFinancialTerm = financialTerms.some((term) => hostname.includes(term));
  const riskyTermCount = riskyTerms.filter((term) => fullUrl.includes(term)).length;
  if (brandTerms.some((term) => hostname.includes(term)) && riskyTerms.some((term) => fullUrl.includes(term))) {
    risk += 20;
    findings.push('The URL combines brand-like and account-action terms.');
  }
  if (hasFinancialTerm && riskyTermCount > 0) {
    risk += 22;
    findings.push('The URL combines financial terms with urgent account-action wording.');
  }
  if (riskyTermCount >= 2) {
    risk += 10;
    findings.push('The URL contains multiple urgent account-action terms.');
  }
  if (hasFinancialTerm && /[-_.](login|verify|update|secure|account|confirm|password)[-_.]?/.test(hostname)) {
    risk += 12;
    findings.push('The domain is shaped like a financial security or account-update page.');
  }
  if (/\/(login|signin|verify|reset|password|account|secure|update)(\/|$|\?)/.test(parsed.pathname)) {
    risk += 8;
    findings.push('The path asks for login or account-verification activity.');
  }
  if (parsed.search.length > 90) {
    risk += 8;
    findings.push('The query string is unusually long.');
  }

  return {
    risk,
    findings,
  };
}

app.post('/api/phishing/check', async (req, res) => {
  const url = typeof req.body?.url === 'string' ? req.body.url.trim() : '';
  if (!url) {
    return res.status(400).json({ status: 'suspicious', score: 0, message: 'URL is required.' });
  }

  const initialScan = evaluateSingleUrl(url);
  if (initialScan.error) {
    return res.status(422).json({ status: 'suspicious', score: 0, message: initialScan.error });
  }

  const redirect = await resolveRedirects(url);
  let risk = initialScan.risk;
  const findings = [...initialScan.findings];
  if (redirect.finalUrl !== url) {
    findings.push(`The URL redirects to: ${redirect.finalUrl}`);
    const finalScan = evaluateSingleUrl(redirect.finalUrl);
    if (!finalScan.error) {
      risk += Math.min(60, finalScan.risk);
      finalScan.findings.forEach((finding) => findings.push(`Final URL: ${finding}`));
    }
  }
  if (redirect.redirectCount > 0) {
    risk += Math.min(12, redirect.redirectCount * 4);
  }
  if (redirect.redirectError) {
    findings.push(redirect.redirectError);
  }

  if (risk >= 55) {
    return res.json({
      status: 'phishing',
      score: Math.min(99, risk),
      message: findings[0] || 'Multiple phishing indicators were detected.',
      findings,
      final_url: redirect.finalUrl,
      redirect_count: redirect.redirectCount,
      redirect_chain: redirect.redirectChain,
    });
  }
  if (risk >= 25) {
    return res.json({
      status: 'suspicious',
      score: risk,
      message: findings[0] || 'Some suspicious URL patterns were detected.',
      findings,
      final_url: redirect.finalUrl,
      redirect_count: redirect.redirectCount,
      redirect_chain: redirect.redirectChain,
    });
  }

  return res.json({
    status: 'safe',
    score: Math.max(90, 100 - risk),
    message: 'No phishing indicators detected.',
    findings,
    final_url: redirect.finalUrl,
    redirect_count: redirect.redirectCount,
    redirect_chain: redirect.redirectChain,
  });
});

app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).json({ error: 'Internal server error' });
});

const port = process.env.PORT || 3001;
app.listen(port, () => {
  console.log(`LEXSHIELD API listening on port ${port}`);
});
