// ../engine/dist/canonical.js
function e(e) {
	return JSON.stringify(t(e));
}
function t(e) {
	if (Array.isArray(e)) return e.map(t);
	if (typeof e == "object" && e) {
		let r = {};
		for (let i of Object.keys(e).sort(n)) r[i] = t(e[i]);
		return r;
	}
	return e;
}
function n(e, t) {
	return e < t ? -1 : +(e > t);
}
var r = new Uint32Array([
	1116352408,
	1899447441,
	3049323471,
	3921009573,
	961987163,
	1508970993,
	2453635748,
	2870763221,
	3624381080,
	310598401,
	607225278,
	1426881987,
	1925078388,
	2162078206,
	2614888103,
	3248222580,
	3835390401,
	4022224774,
	264347078,
	604807628,
	770255983,
	1249150122,
	1555081692,
	1996064986,
	2554220882,
	2821834349,
	2952996808,
	3210313671,
	3336571891,
	3584528711,
	113926993,
	338241895,
	666307205,
	773529912,
	1294757372,
	1396182291,
	1695183700,
	1986661051,
	2177026350,
	2456956037,
	2730485921,
	2820302411,
	3259730800,
	3345764771,
	3516065817,
	3600352804,
	4094571909,
	275423344,
	430227734,
	506948616,
	659060556,
	883997877,
	958139571,
	1322822218,
	1537002063,
	1747873779,
	1955562222,
	2024104815,
	2227730452,
	2361852424,
	2428436474,
	2756734187,
	3204031479,
	3329325298
]);
function i(e) {
	let t = new TextEncoder().encode(e), n = t.length * 8, i = new Uint8Array(Math.ceil((t.length + 9) / 64) * 64);
	i.set(t), i[t.length] = 128;
	let o = new DataView(i.buffer);
	o.setUint32(i.length - 8, Math.floor(n / 4294967296)), o.setUint32(i.length - 4, n >>> 0);
	let s = new Uint32Array([
		1779033703,
		3144134277,
		1013904242,
		2773480762,
		1359893119,
		2600822924,
		528734635,
		1541459225
	]), c = /* @__PURE__ */ new Uint32Array(64);
	for (let e = 0; e < i.length; e += 64) {
		for (let t = 0; t < 16; t++) c[t] = o.getUint32(e + t * 4);
		for (let e = 16; e < 64; e++) {
			let t = c[e - 15] ?? 0, n = c[e - 2] ?? 0, r = a(t, 7) ^ a(t, 18) ^ t >>> 3, i = a(n, 17) ^ a(n, 19) ^ n >>> 10;
			c[e] = (c[e - 16] ?? 0) + r + (c[e - 7] ?? 0) + i >>> 0;
		}
		let [t, n, i, l, u, d, f, p] = [
			s[0] ?? 0,
			s[1] ?? 0,
			s[2] ?? 0,
			s[3] ?? 0,
			s[4] ?? 0,
			s[5] ?? 0,
			s[6] ?? 0,
			s[7] ?? 0
		];
		for (let e = 0; e < 64; e++) {
			let o = a(u, 6) ^ a(u, 11) ^ a(u, 25), s = u & d ^ ~u & f, m = p + o + s + (r[e] ?? 0) + (c[e] ?? 0) >>> 0, h = (a(t, 2) ^ a(t, 13) ^ a(t, 22)) + (t & n ^ t & i ^ n & i) >>> 0;
			p = f, f = d, d = u, u = l + m >>> 0, l = i, i = n, n = t, t = m + h >>> 0;
		}
		let m = [
			t,
			n,
			i,
			l,
			u,
			d,
			f,
			p
		];
		for (let e = 0; e < 8; e++) s[e] = (s[e] ?? 0) + (m[e] ?? 0) >>> 0;
	}
	return Array.from(s, (e) => e.toString(16).padStart(8, "0")).join("");
}
function a(e, t) {
	return e >>> t | e << 32 - t;
}
// ../engine/dist/errors.js
var o = class extends Error {
	code;
	constructor(e, t, n) {
		super(t, n), this.name = "PuzzleError", this.code = e;
	}
}, s = class extends o {
	constructor(e) {
		super("invalid", e), this.name = "InvalidDocumentError";
	}
}, c = class extends o {
	constructor(e) {
		super("illegal", e), this.name = "IllegalActionError";
	}
}, l = /^[a-z][a-z0-9_-]{0,31}$/, u = -(2 ** 53 - 1), d = 2 ** 53 - 1;
function f(e, t) {
	if (Array.isArray(e) && e.length === 0) return {};
	if (typeof e != "object" || !e || Array.isArray(e)) throw new s(`${t} must be an object.`);
	return e;
}
function p(e, t, n = 4096) {
	if (typeof e == "object" && e && !Array.isArray(e) && Object.keys(e).length === 0) return [];
	if (!Array.isArray(e)) throw new s(`${t} must be an array.`);
	if (e.length > n) throw new s(`${t} has more than ${n} entries.`);
	return e;
}
function m(e, t, n) {
	let r = Object.keys(e).filter((e) => !t.includes(e));
	if (r.length > 0) throw new s(`${n} has unknown field(s): ${r.join(", ")}.`);
}
function h(e, t) {
	return Object.prototype.hasOwnProperty.call(e, t);
}
function g(e, t, n, r = u, i = d) {
	if (!h(e, t)) throw new s(`${n}.${t} is required.`);
	return _(e[t], `${n}.${t}`, r, i);
}
function _(e, t, n = u, r = d) {
	if (typeof e != "number" || !Number.isSafeInteger(e)) throw new s(`${t} must be an integer.`);
	if (e < n || e > r) throw new s(`${t} must be between ${n} and ${r}, got ${e}.`);
	return e;
}
function v(e, t, n, r = 2048) {
	if (!h(e, t)) throw new s(`${n}.${t} is required.`);
	return y(e[t], `${n}.${t}`, r);
}
function y(e, t, n = 2048) {
	if (typeof e != "string") throw new s(`${t} must be a string.`);
	if (S(e) > n) throw new s(`${t} is longer than ${n} bytes.`);
	return e;
}
function b(e, t, n) {
	let r = e[t];
	if (!h(e, t) || typeof r != "boolean") throw new s(`${n}.${t} must be a boolean.`);
	return r;
}
function x(e, t) {
	let n = y(e, t, 32);
	if (!l.test(n)) throw new s(`${t} is not a valid identifier: "${n}".`);
	return n;
}
function S(e) {
	let t = 0;
	for (let n of e) {
		let e = n.codePointAt(0) ?? 0;
		t += e < 128 ? 1 : e < 2048 ? 2 : e < 65536 ? 3 : 4;
	}
	return t;
}
// ../engine/dist/prng.js
var C = 4294967295, w = 1 << 20;
function T(e) {
	let t = 2166136261;
	for (let n of new TextEncoder().encode(e)) t = Math.imul(t ^ n, 16777619) >>> 0;
	return t >>> 0;
}
var E = class {
	state;
	constructor(e, t) {
		if (!Number.isInteger(e) || e < 0 || e > 4294967295) throw new o("invalid", "Seed must be an unsigned 32-bit integer.");
		this.state = (T(t) ^ e) >>> 0;
	}
	nextUint32() {
		this.state = this.state + 1831565813 >>> 0;
		let e = this.state;
		return e = Math.imul(e ^ e >>> 15, e | 1), e ^= e + Math.imul(e ^ e >>> 7, e | 61), (e ^ e >>> 14) >>> 0;
	}
	int(e) {
		if (!Number.isInteger(e) || e < 1 || e > 1048576) throw new o("invalid", `Range must be between 1 and ${w}.`);
		return Math.floor(this.nextUint32() * e / 4294967296);
	}
	shuffle(e) {
		let t = [...e];
		for (let e = t.length - 1; e > 0; e--) {
			let n = this.int(e + 1), r = t[e];
			t[e] = t[n], t[n] = r;
		}
		return t;
	}
}, D = 1 << 20;
function O(e, t) {
	let n = f(e, t);
	return m(n, [
		"x",
		"y",
		"width",
		"height"
	], t), {
		x: g(n, "x", t, -1048576, D),
		y: g(n, "y", t, -1048576, D),
		width: g(n, "width", t, 1, D),
		height: g(n, "height", t, 1, D)
	};
}
// ../engine/dist/rules/jigsaw.js
var k = 256, A = /^M[-0-9. LZ]+$/, j = /^[A-Za-z0-9_][A-Za-z0-9._-]*(\/[A-Za-z0-9_][A-Za-z0-9._-]*)*$/, M = 1 << 20;
function N(e) {
	return O(e.attributes.rendered, `tile ${e.id} rendered`);
}
function P(e) {
	return g(e.rules, "snap", "definition.rules", 1, k);
}
function F(e) {
	return O(e.rules.area, "definition.rules.area");
}
function I(e) {
	return O(e.rules.tray, "definition.rules.tray");
}
function L(e, t) {
	return t.x >= e.x && t.y >= e.y && t.x + t.width <= e.x + e.width && t.y + t.height <= e.y + e.height;
}
function R(e) {
	return p(e.pieces, "board.pieces", 4096).map((e, t) => {
		let n = f(e, `board.pieces[${t}]`);
		return {
			x: g(n, "x", `board.pieces[${t}]`),
			y: g(n, "y", `board.pieces[${t}]`),
			placed: b(n, "placed", `board.pieces[${t}]`)
		};
	});
}
var z = {
	kind: "jigsaw",
	actionTypes: ["place"],
	capabilities: {
		undo: !0,
		redo: !0,
		reset: !0,
		shuffle: !0,
		hint: !0
	},
	validateDefinition(e) {
		m(e.rules, [
			"topology",
			"snap",
			"area",
			"tray"
		], "definition.rules");
		let t = v(e.rules, "topology", "definition.rules", 16);
		if (![
			"rect",
			"hex",
			"triangle"
		].includes(t)) throw new s(`Unknown jigsaw topology "${t}".`);
		let n = P(e), r = F(e), i = I(e);
		if (!L(r, i) || !L(r, e.crop)) throw new s("definition.rules.area must contain the board and the tray.");
		if (i.x <= e.crop.x + e.crop.width + n - 1) throw new s("definition.rules.tray must start more than rules.snap pixels right of the board.");
		if (e.tiles.length === 0) throw new s("A jigsaw needs at least one piece.");
		e.tiles.forEach((t, n) => {
			if (t.home !== n) throw new s(`Tile ${t.id} must have home ${n}.`);
			m(t.attributes, [
				"rendered",
				"image",
				"path"
			], `definition.tiles[${n}]`);
			let r = N(t);
			if (!L(e.crop, r) || !L(r, t.rect)) throw new s(`Tile ${t.id}: rendered box must contain its cell and lie on the board.`);
			if (r.width > i.width || r.height > i.height) throw new s(`Tile ${t.id} does not fit in the tray.`);
			if (!h(t.attributes, "image")) throw new s(`Tile ${t.id} has no "image" attribute.`);
			let a = y(t.attributes.image, `definition.tiles[${n}].image`, 255);
			if (!j.test(a)) throw new s(`definition.tiles[${n}].image must be a safe relative path.`);
			if (h(t.attributes, "path")) {
				let e = y(t.attributes.path, `definition.tiles[${n}].path`, M);
				if (!A.test(e)) throw new s(`definition.tiles[${n}].path must be M/L/Z path data.`);
			}
		});
	},
	solvedBoard(e) {
		return { pieces: e.tiles.map((e) => {
			let t = N(e);
			return {
				x: t.x,
				y: t.y,
				placed: !0
			};
		}) };
	},
	scramble(e, t) {
		let n = new E(t, "image-puzzle/scramble/jigsaw"), r = I(e);
		return { pieces: e.tiles.map((e) => {
			let t = N(e);
			return {
				x: r.x + n.int(r.width - t.width + 1),
				y: r.y + n.int(r.height - t.height + 1),
				placed: !1
			};
		}) };
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		m(n, ["pieces"], "board");
		let r = p(n.pieces, "board.pieces", 4096);
		if (r.length !== e.tiles.length) throw new s("board.pieces must have one entry per tile.");
		let i = P(e), a = F(e);
		return { pieces: r.map((t, n) => {
			let r = f(t, `board.pieces[${n}]`);
			m(r, [
				"x",
				"y",
				"placed"
			], `board.pieces[${n}]`);
			let o = g(r, "x", `board.pieces[${n}]`, -1048576, M), c = g(r, "y", `board.pieces[${n}]`, -1048576, M), l = b(r, "placed", `board.pieces[${n}]`), u = N(e.tiles[n]);
			if (l && (o !== u.x || c !== u.y)) throw new s(`board.pieces[${n}] is marked placed away from its solved position.`);
			if (!l && Math.abs(o - u.x) <= i && Math.abs(c - u.y) <= i) throw new s(`board.pieces[${n}] lies inside its snap zone but is not placed.`);
			if (!L(a, {
				x: o,
				y: c,
				width: u.width,
				height: u.height
			})) throw new s(`board.pieces[${n}] lies outside rules.area.`);
			return {
				x: o,
				y: c,
				placed: l
			};
		}) };
	},
	apply(e, t, n) {
		let r = f(n, "action"), i = v(r, "type", "action", 32);
		if (i !== "place") throw new c(`The jigsaw mode does not support "${i}" actions.`);
		m(r, [
			"type",
			"piece",
			"x",
			"y"
		], "action");
		let a = x(r.piece, "action.piece"), o = g(r, "x", "action", -1048576, M), s = g(r, "y", "action", -1048576, M), l = e.tiles.findIndex((e) => e.id === a);
		if (l < 0) throw new c(`Piece "${a}" is not on the board.`);
		let u = R(t);
		if (u[l]?.placed) throw new c(`Piece "${a}" is already placed.`);
		let d = N(e.tiles[l]), p = P(e);
		if (Math.abs(o - d.x) <= p && Math.abs(s - d.y) <= p) return u[l] = {
			x: d.x,
			y: d.y,
			placed: !0
		}, { pieces: u };
		if (!L(F(e), {
			x: o,
			y: s,
			width: d.width,
			height: d.height
		})) throw new c(`Piece "${a}" cannot leave the play area.`);
		return u[l] = {
			x: o,
			y: s,
			placed: !1
		}, { pieces: u };
	},
	legalActions(e, t) {
		let n = [];
		return R(t).forEach((t, r) => {
			if (!t.placed) {
				let t = e.tiles[r], i = N(t);
				n.push({
					type: "place",
					piece: t.id,
					x: i.x,
					y: i.y
				});
			}
		}), n;
	},
	isComplete(e, t) {
		return R(t).every((e) => e.placed);
	},
	progress(e, t) {
		let n = R(t);
		return n.filter((e) => e.placed).length / Math.max(1, n.length);
	},
	hint(e, t) {
		return this.legalActions(e, t)[0] ?? null;
	}
};
// ../engine/dist/rules/grid.js
function B(e) {
	let t = Math.floor(e.crop.width / Math.max(1, e.cols)), n = Math.floor(e.crop.height / Math.max(1, e.rows));
	if (t < 1 || n < 1 || t * e.cols !== e.crop.width || n * e.rows !== e.crop.height) throw new s("board.crop must divide into equal cells.");
	return [t, n];
}
function V(e, t) {
	let [n, r] = B(e), i = Math.floor(t / e.cols), a = t % e.cols;
	return {
		x: e.crop.x + a * n,
		y: e.crop.y + i * r,
		width: n,
		height: r
	};
}
function H(e, t, n, r) {
	let { kind: i, rows: a, cols: o, tiles: c } = e;
	if (a < t || o < n || a > r || o > r) throw new s(`A ${i} board must be between ${t}x${n} and ${r}x${r}, got ${a}x${o}.`);
	let l = a * o;
	if (l < 2) throw new s(`A ${i} board needs at least two tiles.`);
	if (c.length !== l) throw new s(`A ${a}x${o} ${i} board needs exactly ${l} tiles, got ${c.length}.`);
	c.forEach((t, n) => {
		if (t.home !== n) throw new s(`Tile ${t.id} must have home ${n} (tiles are listed in home order).`);
		let r = V(e, n);
		if (t.rect.x !== r.x || t.rect.y !== r.y || t.rect.width !== r.width || t.rect.height !== r.height) throw new s(`Tile ${t.id} does not show cell ${n} of board.crop.`);
		let i = Object.keys(t.attributes);
		if (i.length > 0) throw new s(`Tile ${t.id} has unknown field(s): ${i.join(", ")}.`);
	});
}
function U(e) {
	return e.tiles.map((e) => e.id);
}
function ee(e, t, n) {
	let r = p(e, n, 4096);
	if (r.length !== t.length) throw new s(`${n} must list ${t.length} tiles, got ${r.length}.`);
	let i = new Set(t), a = /* @__PURE__ */ new Set();
	return r.map((e, t) => {
		let r = x(e, `${n}[${t}]`);
		if (!i.has(r)) throw new s(`${n}[${t}] is an unknown tile "${r}".`);
		if (a.has(r)) throw new s(`${n}[${t}] repeats tile "${r}".`);
		return a.add(r), r;
	});
}
function W(e, t) {
	let n = t.shuffle(e);
	if (n.length > 1 && n.every((t, n) => t === e[n])) {
		let e = n[0];
		n[0] = n[1], n[1] = e;
	}
	return n;
}
function G(e, t, n, r) {
	let i = f(n, "action"), a = v(i, "type", "action", 32);
	if (!t.includes(a)) throw new c(`The ${e} mode does not support "${a}" actions.`);
	return m(i, ["type", ...r], "action"), i;
}
function K(e, t) {
	return p(e[t], `board.${t}`, 4096).map((e) => y(e, `board.${t}[]`, 32));
}
function te(e, t) {
	return e.length === t.length && e.every((e, n) => e === t[n]);
}
// ../engine/dist/rules/rotation.js
var ne = ["rotate"], re = [
	0,
	90,
	180,
	270
];
function q(e) {
	let t = p(e.rules.orientations, "definition.rules.orientations", 4).map((e, t) => _(e, `definition.rules.orientations[${t}]`)), n = [...t].sort((e, t) => e - t);
	if (!(t.every((e, t) => e === n[t]) && new Set(t).size === t.length && t.every((e) => re.includes(e)) && t.length >= 2 && t[0] === 0)) throw new s("definition.rules.orientations must be an ascending subset of [0, 90, 180, 270] with 0 and at least two angles.");
	return t;
}
function ie(e) {
	return p(e.orientations, "board.orientations", 4096).map((e) => _(e, "board.orientations[]"));
}
var ae = {
	kind: "rotation",
	actionTypes: ne,
	capabilities: {
		undo: !0,
		redo: !0,
		reset: !0,
		shuffle: !0,
		hint: !0
	},
	validateDefinition(e) {
		H(e, 1, 1, 16), m(e.rules, ["orientations"], "definition.rules");
		let t = q(e), [n, r] = B(e);
		if (n !== r && (t.includes(90) || t.includes(270))) throw new s(`Quarter turns need square cells; cells are ${n}x${r}.`);
	},
	solvedBoard(e) {
		return { orientations: e.tiles.map(() => 0) };
	},
	scramble(e, t) {
		let n = new E(t, "image-puzzle/scramble/rotation"), r = q(e), i = e.tiles.map(() => r[n.int(r.length)]);
		return i.some((e) => e !== 0) || (i[0] = r[1]), { orientations: i };
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		m(n, ["orientations"], "board");
		let r = p(n.orientations, "board.orientations", 4096);
		if (r.length !== e.tiles.length) throw new s("board.orientations must have one entry per tile.");
		let i = q(e);
		return { orientations: r.map((e, t) => {
			let n = _(e, `board.orientations[${t}]`);
			if (!i.includes(n)) throw new s(`board.orientations[${t}] = ${n} is not an allowed orientation.`);
			return n;
		}) };
	},
	apply(e, t, n) {
		let r = G("rotation", ne, n, ["tile", "direction"]), i = x(r.tile, "action.tile"), a = v(r, "direction", "action", 8);
		if (a !== "cw" && a !== "ccw") throw new s("action.direction must be \"cw\" or \"ccw\".");
		let o = e.tiles.findIndex((e) => e.id === i);
		if (o < 0) throw new c(`Tile "${i}" is not on the board.`);
		let l = ie(t), u = q(e), d = u.indexOf(l[o] ?? -1);
		if (d < 0) throw new s(`board.orientations[${o}] is not an allowed orientation.`);
		let f = u.length;
		return l[o] = u[(d + (a === "cw" ? 1 : f - 1)) % f], { orientations: l };
	},
	legalActions(e) {
		let t = q(e).length > 2, n = [];
		for (let r of e.tiles) n.push({
			type: "rotate",
			tile: r.id,
			direction: "cw"
		}), t && n.push({
			type: "rotate",
			tile: r.id,
			direction: "ccw"
		});
		return n;
	},
	isComplete(e, t) {
		return ie(t).every((e) => e === 0);
	},
	progress(e, t) {
		let n = ie(t);
		return n.filter((e) => e === 0).length / Math.max(1, n.length);
	},
	hint(e, t) {
		let n = q(e), r = n.length, i = ie(t);
		for (let t = 0; t < i.length; t++) {
			let a = i[t];
			if (a !== 0) {
				let i = Math.max(0, n.indexOf(a));
				return {
					type: "rotate",
					tile: e.tiles[t]?.id ?? "",
					direction: i < r - i ? "ccw" : "cw"
				};
			}
		}
		return null;
	}
}, oe = 16, J = 1e5, se = ["slide"];
function ce(e) {
	return x(e.rules.empty, "definition.rules.empty");
}
function Y(e) {
	return p(e.cells, "board.cells", 4096).map((e) => e === null ? null : y(e, "board.cells[]", 32));
}
function le(e) {
	let t = e.indexOf(null);
	if (t < 0) throw new s("board.cells has no empty cell.");
	return t;
}
function ue(e, t) {
	let { cols: n, rows: r } = e, i = Math.floor(t / n), a = t % n, o = [];
	return i > 0 && o.push(t - n), a > 0 && o.push(t - 1), a < n - 1 && o.push(t + 1), i < r - 1 && o.push(t + n), o;
}
function de(e, t) {
	let n = Y(t), r = [];
	for (let t of ue(e, le(n))) {
		let e = n[t];
		typeof e == "string" && r.push(e);
	}
	return r;
}
function fe(e, t) {
	let n = ce(e), r = new Map(e.tiles.map((e) => [e.id, e.home])), i = t.map((e) => r.get(e ?? n) ?? -1), a = /* @__PURE__ */ new Set(), o = 0;
	i.forEach((e, t) => {
		if (a.has(t)) return;
		let n = 0;
		for (let e = t; !a.has(e); e = i[e] ?? t) a.add(e), n++;
		o += n - 1;
	});
	let s = le(t), c = r.get(n) ?? 0, { cols: l } = e, u = Math.abs(Math.floor(s / l) - Math.floor(c / l)) + Math.abs(s % l - c % l);
	return o % 2 == u % 2;
}
var pe = {
	kind: "sliding",
	actionTypes: se,
	capabilities: {
		undo: !0,
		redo: !0,
		reset: !0,
		shuffle: !0,
		hint: !1
	},
	validateDefinition(e) {
		H(e, 2, 2, oe), m(e.rules, ["empty", "scrambleMoves"], "definition.rules");
		let t = x(e.rules.empty, "definition.rules.empty");
		if (!e.tiles.some((e) => e.id === t)) throw new s(`definition.rules.empty names an unknown tile "${t}".`);
		g(e.rules, "scrambleMoves", "definition.rules", 1, J);
	},
	solvedBoard(e) {
		let t = ce(e);
		return { cells: U(e).map((e) => e === t ? null : e) };
	},
	scramble(e, t) {
		let n = new E(t, "image-puzzle/scramble/sliding"), r = this.solvedBoard(e), i = JSON.stringify(r), a = g(e.rules, "scrambleMoves", "definition.rules", 1, J), o = null, c = a + 4 * e.tiles.length;
		for (let t = 0; t < c && (t < a || JSON.stringify(r) === i); t++) {
			let t = de(e, r).filter((e) => e !== o);
			o = t[n.int(t.length)] ?? null, r = this.apply(e, r, {
				type: "slide",
				tile: o
			});
		}
		if (JSON.stringify(r) === i) throw new s("Could not produce an unsolved sliding scramble.");
		return r;
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		m(n, ["cells"], "board");
		let r = p(n.cells, "board.cells", 4096), i = e.tiles.length;
		if (r.length !== i) throw new s(`board.cells must have ${i} entries, got ${r.length}.`);
		let a = ce(e), o = U(e).filter((e) => e !== a);
		if (r.filter((e) => e === null).length !== 1) throw new s("board.cells must contain exactly one empty cell (null).");
		ee(r.filter((e) => e !== null), o, "board.cells");
		let c = r.map((e) => typeof e == "string" ? e : null);
		if (!fe(e, c)) throw new s("board.cells is not reachable by legal slides from the solved board.");
		return { cells: c };
	},
	apply(e, t, n) {
		let r = x(G("sliding", se, n, ["tile"]).tile, "action.tile"), i = Y(t), a = i.indexOf(r);
		if (a < 0) throw new c(`Tile "${r}" is not on the board.`);
		let o = le(i);
		if (!ue(e, o).includes(a)) throw new c(`Tile "${r}" is not next to the empty cell.`);
		return i[o] = r, i[a] = null, { cells: i };
	},
	legalActions(e, t) {
		return de(e, t).map((e) => ({
			type: "slide",
			tile: e
		}));
	},
	isComplete(e, t) {
		return JSON.stringify(Y(t)) === JSON.stringify(Y(this.solvedBoard(e)));
	},
	progress(e, t) {
		let n = Y(t), r = Y(this.solvedBoard(e));
		return n.filter((e, t) => e !== null && e === r[t]).length / Math.max(1, n.length - 1);
	},
	hint() {
		return null;
	}
}, me = ["move"];
function he(e) {
	let t = v(e.rules, "axis", "definition.rules", 16);
	if (t !== "horizontal" && t !== "vertical") throw new s("definition.rules.axis must be \"horizontal\" or \"vertical\".");
	return t;
}
var ge = {
	kind: "strips",
	actionTypes: me,
	capabilities: {
		undo: !0,
		redo: !0,
		reset: !0,
		shuffle: !0,
		hint: !0
	},
	validateDefinition(e) {
		m(e.rules, ["axis"], "definition.rules");
		let t = he(e);
		if (t === "horizontal" && e.cols !== 1) throw new s("Horizontal strips need board.cols = 1.");
		if (t === "vertical" && e.rows !== 1) throw new s("Vertical strips need board.rows = 1.");
		H(e, 1, 1, 128);
	},
	solvedBoard(e) {
		return { order: U(e) };
	},
	scramble(e, t) {
		return { order: W(U(e), new E(t, "image-puzzle/scramble/strips")) };
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		return m(n, ["order"], "board"), { order: ee(n.order, U(e), "board.order") };
	},
	apply(e, t, n) {
		let r = G("strips", me, n, ["strip", "to"]), i = x(r.strip, "action.strip"), a = K(t, "order"), o = g(r, "to", "action", 0, Math.max(0, a.length - 1)), s = a.indexOf(i);
		if (s < 0) throw new c(`Strip "${i}" is not on the board.`);
		if (s === o) throw new c(`Strip "${i}" is already at position ${o}.`);
		return a.splice(s, 1), a.splice(o, 0, i), { order: a };
	},
	legalActions(e, t) {
		let n = K(t, "order"), r = [];
		return n.forEach((e, t) => {
			n.forEach((n, i) => {
				i !== t && r.push({
					type: "move",
					strip: e,
					to: i
				});
			});
		}), r;
	},
	isComplete(e, t) {
		return te(K(t, "order"), U(e));
	},
	progress(e, t) {
		let n = U(e);
		return K(t, "order").filter((e, t) => e === n[t]).length / Math.max(1, n.length);
	},
	hint(e, t) {
		let n = K(t, "order"), r = U(e);
		for (let e = 0; e < r.length; e++) if (n[e] !== r[e]) return {
			type: "move",
			strip: r[e],
			to: e
		};
		return null;
	}
}, _e = ["swap"], ve = {
	sliding: pe,
	swap: {
		kind: "swap",
		actionTypes: _e,
		capabilities: {
			undo: !0,
			redo: !0,
			reset: !0,
			shuffle: !0,
			hint: !0
		},
		validateDefinition(e) {
			H(e, 1, 1, 16), m(e.rules, [], "definition.rules");
		},
		solvedBoard(e) {
			return { cells: U(e) };
		},
		scramble(e, t) {
			return { cells: W(U(e), new E(t, "image-puzzle/scramble/swap")) };
		},
		validateBoard(e, t) {
			let n = f(t, "board");
			return m(n, ["cells"], "board"), { cells: ee(n.cells, U(e), "board.cells") };
		},
		apply(e, t, n) {
			let r = G("swap", _e, n, ["a", "b"]), i = x(r.a, "action.a"), a = x(r.b, "action.b");
			if (i === a) throw new c("A swap needs two different tiles.");
			let o = K(t, "cells"), s = o.indexOf(i), l = o.indexOf(a);
			if (s < 0 || l < 0) throw new c("Both tiles of a swap must be on the board.");
			return o[s] = a, o[l] = i, { cells: o };
		},
		legalActions(e, t) {
			let n = K(t, "cells"), r = [];
			for (let e = 0; e < n.length; e++) for (let t = e + 1; t < n.length; t++) r.push({
				type: "swap",
				a: n[e],
				b: n[t]
			});
			return r;
		},
		isComplete(e, t) {
			return te(K(t, "cells"), U(e));
		},
		progress(e, t) {
			let n = U(e);
			return K(t, "cells").filter((e, t) => e === n[t]).length / Math.max(1, n.length);
		},
		hint(e, t) {
			let n = K(t, "cells"), r = U(e);
			for (let e = 0; e < r.length; e++) {
				let t = n[e];
				if (t === void 0) throw new s("board.cells is incomplete.");
				if (t !== r[e]) return {
					type: "swap",
					a: t,
					b: r[e]
				};
			}
			return null;
		}
	},
	rotation: ae,
	strips: ge,
	jigsaw: z
};
function X(e) {
	let t = ve[e];
	if (t === void 0) throw new o("unsupported", `The ${e} mode is not available in this version.`);
	return t;
}
// ../engine/dist/types.js
var ye = [
	"sliding",
	"swap",
	"rotation",
	"strips",
	"jigsaw",
	"memory",
	"tangram",
	"nonogram"
], be = "image-puzzle/game-definition", xe = 4096, Se = /^(?:https:\/\/[A-Za-z0-9._~:/?#[\]@!$&'()*+,;=%-]+|[A-Za-z0-9_~-][A-Za-z0-9._~/-]*)$/, Ce = /(^|\/)\.\.?(\/|$)/, we = [
	"$schema",
	"format",
	"version",
	"id",
	"kind",
	"generator",
	"seed",
	"preset",
	"source",
	"media",
	"board",
	"tiles",
	"rules",
	"capabilities",
	"actions",
	"assistance",
	"initial"
];
function Te(e) {
	let t = f(e, "definition");
	if (m(t, we, "definition"), t.format !== "image-puzzle/game-definition") throw new s(`definition.format must be "${be}".`);
	let n = g(t, "version", "definition", 1);
	if (n !== 1) throw new s(`Unsupported game definition version ${n}; this library reads version 1.`);
	let r = v(t, "kind", "definition", 32);
	if (!ye.includes(r)) throw new s("definition.kind is not a supported game kind.");
	let i = r, a = f(t.generator, "definition.generator");
	m(a, ["algorithm"], "definition.generator");
	let o = g(a, "algorithm", "definition.generator", 1);
	if (o !== 1) throw new s(`Unsupported generator algorithm ${o}.`);
	let c = f(t.preset, "definition.preset");
	m(c, ["name", "version"], "definition.preset");
	let l = f(t.source, "definition.source");
	m(l, [
		"width",
		"height",
		"sha256"
	], "definition.source");
	let u = l.sha256 ?? null;
	if (u !== null && (typeof u != "string" || !/^[0-9a-f]{64}$/.test(u))) throw new s("definition.source.sha256 must be null or 64 lowercase hex digits.");
	let d = f(t.media, "definition.media");
	m(d, ["image"], "definition.media");
	let h = f(t.board, "definition.board");
	m(h, [
		"rows",
		"cols",
		"crop"
	], "definition.board");
	let _ = p(t.tiles, "definition.tiles", xe).map((e, t) => Oe(e, `definition.tiles[${t}]`)), y = f(t.assistance, "definition.assistance");
	m(y, [
		"preview",
		"numbers",
		"highlightCorrect"
	], "definition.assistance");
	let x = f(t.rules, "definition.rules"), S = {
		kind: i,
		seed: g(t, "seed", "definition", 0, C),
		preset: {
			name: v(c, "name", "definition.preset", 32),
			version: g(c, "version", "definition.preset", 1)
		},
		source: {
			width: g(l, "width", "definition.source", 1, 1 << 20),
			height: g(l, "height", "definition.source", 1, 1 << 20),
			sha256: u
		},
		media: { image: v(d, "image", "definition.media") },
		rows: g(h, "rows", "definition.board", 1, 1024),
		cols: g(h, "cols", "definition.board", 1, 1024),
		crop: O(h.crop, "definition.board.crop"),
		tiles: _,
		rules: structuredClone(x),
		assistance: {
			preview: b(y, "preview", "definition.assistance"),
			numbers: b(y, "numbers", "definition.assistance"),
			highlightCorrect: b(y, "highlightCorrect", "definition.assistance")
		}
	}, w = v(t, "id", "definition", 80);
	ke(S);
	let T = X(i);
	if (T.validateDefinition(S), JSON.stringify(t.capabilities) !== JSON.stringify(T.capabilities)) throw new s(`definition.capabilities do not match the ${i} rules.`);
	if (JSON.stringify(t.actions) !== JSON.stringify(T.actionTypes)) throw new s(`definition.actions do not match the ${i} rules.`);
	let E = T.validateBoard(S, t.initial);
	if (T.isComplete(S, E)) throw new s("definition.initial is already solved.");
	let D = {
		...S,
		id: w,
		actions: [...T.actionTypes],
		capabilities: { ...T.capabilities },
		initial: E
	}, k = Ee(D);
	if (k !== w) throw new s(`definition.id does not match its content (expected ${k}).`);
	return Ae(D);
}
function Ee(t) {
	return `gd1-${i(e(De(t)))}`;
}
function De(e) {
	return {
		format: be,
		version: 1,
		kind: e.kind,
		generator: { algorithm: 1 },
		seed: e.seed,
		preset: e.preset,
		source: e.source,
		media: e.media,
		board: {
			rows: e.rows,
			cols: e.cols,
			crop: e.crop
		},
		tiles: e.tiles.map((e) => ({
			id: e.id,
			home: e.home,
			rect: e.rect,
			...e.attributes
		})),
		rules: e.rules,
		actions: e.actions,
		capabilities: e.capabilities,
		assistance: e.assistance,
		initial: e.initial
	};
}
function Oe(e, t) {
	let n = f(e, t), r = x(n.id, `${t}.id`), i = g(n, "home", t, 0, 1 << 20), a = O(n.rect, `${t}.rect`), o = {};
	for (let [e, t] of Object.entries(n)) e !== "id" && e !== "home" && e !== "rect" && (o[e] = structuredClone(t));
	return {
		id: r,
		home: i,
		rect: a,
		attributes: o
	};
}
function ke(e) {
	if (!/^(easy|medium|hard|custom)$/.test(e.preset.name)) throw new s(`Unknown preset "${e.preset.name}".`);
	if (!Se.test(e.media.image) || Ce.test(e.media.image)) throw new s("media.image must be a relative path without dot segments or an https URL.");
	let { crop: t, source: n } = e;
	if (t.x < 0 || t.y < 0 || t.x + t.width > n.width || t.y + t.height > n.height) throw new s("board.crop must lie inside the source image.");
	let r = /* @__PURE__ */ new Set();
	for (let t of e.tiles) {
		if (r.has(t.id)) throw new s(`Duplicate tile id "${t.id}".`);
		r.add(t.id);
	}
}
function Ae(e) {
	if (typeof e == "object" && e && !Object.isFrozen(e)) {
		Object.freeze(e);
		for (let t of Object.values(e)) Ae(t);
	}
	return e;
}
// ../engine/dist/state.js
var je = "image-puzzle/game-state", Me = 100, Ne = "https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/state.schema.json";
function Pe(e, t) {
	let n = f(t, "state");
	if (m(n, [
		"$schema",
		"format",
		"version",
		"definitionId",
		"kind",
		"board",
		"moves",
		"elapsedMs",
		"hintsUsed",
		"completed",
		"history"
	], "state"), n.format !== "image-puzzle/game-state") throw new s(`state.format must be "${je}".`);
	let r = g(n, "version", "state", 1);
	if (r !== 1) throw new s(`Unsupported game state version ${r}; this library reads version 1.`);
	if (v(n, "definitionId", "state", 80) !== e.id) throw new s("state.definitionId belongs to another puzzle.");
	if (v(n, "kind", "state", 32) !== e.kind) throw new s("state.kind does not match the definition.");
	let i = X(e.kind), a = i.validateBoard(e, n.board), o = b(n, "completed", "state");
	if (o !== i.isComplete(e, a)) throw new s("state.completed does not match the board.");
	let c = f(n.history, "state.history");
	m(c, ["past", "future"], "state.history");
	let l = p(c.past, "state.history.past", 100).map((t) => i.validateBoard(e, t)), u = p(c.future, "state.history.future", 100).map((t) => i.validateBoard(e, t));
	return {
		board: a,
		moves: g(n, "moves", "state", 0),
		elapsedMs: g(n, "elapsedMs", "state", 0),
		hintsUsed: g(n, "hintsUsed", "state", 0),
		completed: o,
		past: l,
		future: u
	};
}
function Fe(e, t) {
	return structuredClone({
		$schema: Ne,
		format: je,
		version: 1,
		definitionId: e.id,
		kind: e.kind,
		board: t.board,
		moves: t.moves,
		elapsedMs: t.elapsedMs,
		hintsUsed: t.hintsUsed,
		completed: t.completed,
		history: {
			past: t.past,
			future: t.future
		}
	});
}
// ../engine/dist/engine.js
function Ie() {
	return typeof performance < "u" ? performance.now() : Date.now();
}
function Le() {
	return typeof crypto < "u" && typeof crypto.getRandomValues == "function" ? crypto.getRandomValues(/* @__PURE__ */ new Uint32Array(1))[0] ?? 0 : Math.floor(Math.random() * C);
}
function Re(e = {}) {
	let t = e.clock ?? Ie, n = e.randomSeed ?? Le, r = e.onListenerError ?? ((e) => {
		queueMicrotask(() => {
			throw e;
		});
	}), i = /* @__PURE__ */ new Map(), a = null, s = "empty", l = 0, u = null;
	function d() {
		if (s === "destroyed") throw new o("destroyed", "The puzzle engine was destroyed.");
	}
	function f() {
		if (d(), a === null) throw new o("invalid", "No puzzle is loaded.");
		return a;
	}
	function p() {
		return Math.floor(l + (u === null ? 0 : Math.max(0, t() - u)));
	}
	function m() {
		l = p(), u = null;
	}
	function h(e, t) {
		let n = i.get(e);
		if (n !== void 0) for (let e of [...n]) try {
			e(t);
		} catch (e) {
			r(e);
		}
	}
	function g(e) {
		return Fe(e.definition, {
			...e.state,
			elapsedMs: p()
		});
	}
	function _(e) {
		return X(e.definition.kind).progress(e.definition, e.state.board);
	}
	function v(e) {
		let t = f();
		h("state-change", {
			reason: e,
			status: s,
			state: g(t)
		}), h("progress", {
			progress: _(t),
			moves: t.state.moves,
			elapsedMs: p()
		});
	}
	function y(e) {
		if (e instanceof o) throw h("error", {
			code: e.code,
			message: e.message
		}), e;
		let t = new o("invalid", e instanceof Error ? e.message : String(e), { cause: e });
		throw h("error", {
			code: t.code,
			message: t.message
		}), t;
	}
	function b() {
		(s === "idle" || s === "paused") && (s = "running", u = t());
	}
	function x(e, t) {
		return [...e, t].slice(-100);
	}
	function S(e, t, n, r) {
		let i = X(e.definition.kind).isComplete(e.definition, t);
		e.state = {
			...e.state,
			board: t,
			past: n,
			future: r,
			moves: e.state.moves + 1,
			completed: i
		}, i && (m(), s = "complete");
	}
	function C(e) {
		e.state.completed && h("complete", {
			definitionId: e.definition.id,
			moves: e.state.moves,
			elapsedMs: p(),
			hintsUsed: e.state.hintsUsed
		});
	}
	function w(e, t) {
		return {
			board: t,
			moves: 0,
			elapsedMs: 0,
			hintsUsed: 0,
			completed: !1,
			past: [],
			future: []
		};
	}
	let T = {
		get definition() {
			return a?.definition ?? null;
		},
		get status() {
			return s;
		},
		load(e, t) {
			d();
			let n, r;
			try {
				n = Te(e), r = t === void 0 ? w(n, n.initial) : Pe(n, t);
			} catch (e) {
				y(e);
			}
			a = {
				definition: n,
				state: r
			}, l = r.elapsedMs, u = null, s = r.completed ? "complete" : "idle", v("load"), h("ready", {
				definitionId: n.id,
				kind: n.kind,
				state: g(a)
			});
		},
		start() {
			f(), b(), v("start");
		},
		pause() {
			f(), s === "running" && (m(), s = "paused"), v("pause");
		},
		reset() {
			let e = f();
			e.state = w(e.definition, e.definition.initial), l = 0, u = null, s = "idle", v("reset");
		},
		shuffle(e) {
			let t = f(), r = e ?? n(), i;
			try {
				i = X(t.definition.kind).scramble(t.definition, r);
			} catch (e) {
				y(e);
			}
			t.state = w(t.definition, i), l = 0, u = null, s = "idle", v("shuffle");
		},
		dispatchAction(e) {
			let t = f();
			if (t.state.completed) return {
				ok: !1,
				error: new c("The puzzle is already complete.")
			};
			let n;
			try {
				n = X(t.definition.kind).apply(t.definition, t.state.board, e);
			} catch (e) {
				if (e instanceof o) return {
					ok: !1,
					error: e
				};
				throw e;
			}
			return b(), S(t, n, x(t.state.past, t.state.board), []), h("move", {
				action: structuredClone(e),
				state: g(t)
			}), v("action"), C(t), { ok: !0 };
		},
		legalActions() {
			let e = f();
			return e.state.completed ? [] : X(e.definition.kind).legalActions(e.definition, e.state.board);
		},
		hint() {
			let e = f();
			if (!e.definition.capabilities.hint || e.state.completed) return null;
			let t = X(e.definition.kind).hint(e.definition, e.state.board);
			return t === null ? null : (e.state = {
				...e.state,
				hintsUsed: e.state.hintsUsed + 1
			}, v("hint"), t);
		},
		undo() {
			let e = f();
			if (!T.canUndo()) return !1;
			let t = [...e.state.past], n = t.pop();
			return b(), S(e, n, t, x(e.state.future, e.state.board)), v("undo"), C(e), !0;
		},
		redo() {
			let e = f();
			if (!T.canRedo()) return !1;
			let t = [...e.state.future], n = t.pop();
			return b(), S(e, n, x(e.state.past, e.state.board), t), v("redo"), C(e), !0;
		},
		canUndo() {
			let e = f();
			return e.definition.capabilities.undo && !e.state.completed && e.state.past.length > 0;
		},
		canRedo() {
			let e = f();
			return e.definition.capabilities.redo && !e.state.completed && e.state.future.length > 0;
		},
		exportState() {
			return g(f());
		},
		importState(e) {
			let t = f(), n;
			try {
				n = Pe(t.definition, e);
			} catch (e) {
				y(e);
			}
			t.state = n, l = n.elapsedMs, u = null, s = n.completed ? "complete" : "idle", v("import");
		},
		snapshot() {
			return d(), a === null ? {
				status: s,
				board: null,
				moves: 0,
				elapsedMs: 0,
				hintsUsed: 0,
				progress: 0,
				completed: !1,
				canUndo: !1,
				canRedo: !1
			} : {
				status: s,
				board: structuredClone(a.state.board),
				moves: a.state.moves,
				elapsedMs: p(),
				hintsUsed: a.state.hintsUsed,
				progress: _(a),
				completed: a.state.completed,
				canUndo: T.canUndo(),
				canRedo: T.canRedo()
			};
		},
		subscribe(e, t) {
			d();
			let n = i.get(e);
			n === void 0 && (n = /* @__PURE__ */ new Set(), i.set(e, n));
			let r = t;
			return n.add(r), () => {
				i.get(e)?.delete(r);
			};
		},
		destroy() {
			s !== "destroyed" && (m(), i.clear(), a = null, s = "destroyed");
		}
	};
	return e.definition !== void 0 && T.load(e.definition, e.state), T;
}
// ../engine/dist/persistence.js
var ze = "image-puzzle";
function Be() {
	try {
		let e = globalThis.localStorage;
		if (e === void 0) return null;
		let t = `${ze}:probe`;
		return e.setItem(t, "1"), e.removeItem(t), e;
	} catch {
		return null;
	}
}
function Ve(e, t = ze) {
	return `${t}:${e}`;
}
function He(e, t, n = ze) {
	try {
		let r = e.exportState();
		return t.setItem(Ve(r.definitionId, n), JSON.stringify(r)), { ok: !0 };
	} catch (e) {
		return {
			ok: !1,
			error: Ge(e)
		};
	}
}
function Ue(e, t, n = ze) {
	let r = e.definition;
	if (r === null) return {
		restored: !1,
		error: new o("invalid", "No puzzle is loaded.")
	};
	let i;
	try {
		i = t.getItem(Ve(r.id, n));
	} catch (e) {
		return {
			restored: !1,
			error: Ge(e)
		};
	}
	if (i === null) return { restored: !1 };
	let a;
	try {
		a = JSON.parse(i);
	} catch {
		return {
			restored: !1,
			error: new o("storage", "The saved game is not valid JSON.")
		};
	}
	try {
		return e.importState(a), { restored: !0 };
	} catch (e) {
		return {
			restored: !1,
			error: e instanceof o ? e : Ge(e)
		};
	}
}
function We(e, t, n = {}) {
	let r = n.debounceMs ?? 250, i = null, a = !1, o = () => {
		if (i = null, e.status === "destroyed" || e.definition === null) return;
		let r = He(e, t, n.prefix);
		r.ok || n.onError?.(r.error);
	}, s = e.subscribe("state-change", () => {
		a || (i !== null && clearTimeout(i), i = setTimeout(o, r));
	});
	return () => {
		a || (a = !0, s(), i !== null && (clearTimeout(i), o()));
	};
}
function Ge(e) {
	return new o("storage", e instanceof Error ? e.message : String(e), { cause: e });
}
// src/dom.ts
function Z(e, t, n = {}, r) {
	let i = e.createElement(t);
	for (let [e, t] of Object.entries(n)) i.setAttribute(e, t);
	return r !== void 0 && (i.textContent = r), i;
}
function Q(e) {
	return `${Math.round(e * 1e4) / 1e4}%`;
}
function Ke(e, t) {
	let n = new URL(e, t);
	if (![
		"http:",
		"https:",
		"blob:",
		"file:"
	].includes(n.protocol)) throw Error(`Unsupported asset URL scheme ${n.protocol}`);
	return n.href;
}
function qe(e) {
	return `url("${e.replace(/["\\\n\r]/g, (e) => encodeURIComponent(e))}")`;
}
// src/messages.ts
var Je = {
	label: "Image puzzle",
	controls: "Puzzle controls",
	loading: "Loading puzzle…",
	empty: "No puzzle loaded.",
	loadError: "The puzzle could not be loaded: {message}",
	start: "Start",
	pause: "Pause",
	resume: "Resume",
	undo: "Undo",
	redo: "Redo",
	reset: "Reset",
	shuffle: "Shuffle",
	hint: "Hint",
	preview: "Show picture",
	zoomIn: "Zoom in",
	zoomOut: "Zoom out",
	moves: "Moves: {n}",
	time: "Time: {time}",
	progress: "{percent}% correct",
	hints: "Hints: {n}",
	paused: "Paused",
	complete: "Puzzle complete in {moves} moves and {time}.",
	illegal: "That move is not allowed.",
	tile: "Tile {n}, row {row}, column {col}",
	tileSelected: "Tile {n} selected",
	strip: "Strip {n}, position {position} of {count}",
	piece: "Piece {n}",
	piecePlaced: "Piece {n}, placed",
	orientation: "rotated {degrees} degrees",
	moved: "Moved tile {n}.",
	swapped: "Swapped tiles {a} and {b}.",
	rotated: "Tile {n} now {degrees} degrees.",
	placed: "Piece {n} placed.",
	dropped: "Piece {n} dropped.",
	hintGiven: "Hint: {hint}",
	noHint: "No hint available.",
	instructions: {
		sliding: "Use the arrow keys to move between tiles and Enter to slide a tile next to the gap.",
		swap: "Press Enter on two tiles to swap them. Escape cancels the selection.",
		rotation: "Press Enter to rotate a tile clockwise, Shift+Enter to rotate it counter-clockwise.",
		strips: "Press Enter to pick up a strip, the arrow keys to move it, and Enter again to drop it.",
		jigsaw: "Tab to a piece, move it with the arrow keys (Shift for larger steps) and press Enter to drop it."
	}
}, Ye = {
	label: "Puzzle",
	controls: "Comandi del puzzle",
	loading: "Caricamento del puzzle…",
	empty: "Nessun puzzle caricato.",
	loadError: "Impossibile caricare il puzzle: {message}",
	start: "Inizia",
	pause: "Pausa",
	resume: "Riprendi",
	undo: "Annulla",
	redo: "Ripeti",
	reset: "Ricomincia",
	shuffle: "Mescola",
	hint: "Suggerimento",
	preview: "Mostra l'immagine",
	zoomIn: "Ingrandisci",
	zoomOut: "Riduci",
	moves: "Mosse: {n}",
	time: "Tempo: {time}",
	progress: "{percent}% corretto",
	hints: "Suggerimenti: {n}",
	paused: "In pausa",
	complete: "Puzzle completato in {moves} mosse e {time}.",
	illegal: "Mossa non consentita.",
	tile: "Tessera {n}, riga {row}, colonna {col}",
	tileSelected: "Tessera {n} selezionata",
	strip: "Striscia {n}, posizione {position} di {count}",
	piece: "Pezzo {n}",
	piecePlaced: "Pezzo {n}, posizionato",
	orientation: "ruotata di {degrees} gradi",
	moved: "Tessera {n} spostata.",
	swapped: "Tessere {a} e {b} scambiate.",
	rotated: "Tessera {n} ora a {degrees} gradi.",
	placed: "Pezzo {n} posizionato.",
	dropped: "Pezzo {n} lasciato.",
	hintGiven: "Suggerimento: {hint}",
	noHint: "Nessun suggerimento disponibile.",
	instructions: {
		sliding: "Usa le frecce per spostarti tra le tessere e Invio per far scorrere una tessera vicina allo spazio vuoto.",
		swap: "Premi Invio su due tessere per scambiarle. Esc annulla la selezione.",
		rotation: "Premi Invio per ruotare una tessera in senso orario, Maiusc+Invio in senso antiorario.",
		strips: "Premi Invio per prendere una striscia, le frecce per spostarla e di nuovo Invio per lasciarla.",
		jigsaw: "Usa Tab per scegliere un pezzo, le frecce per spostarlo (Maiusc per passi più lunghi) e Invio per lasciarlo."
	}
}, Xe = {
	en: Je,
	it: Ye
};
function Ze(e, t) {
	return {
		...(e === void 0 ? void 0 : Xe[e] ?? Xe[e.split("-")[0] ?? ""]) ?? Je,
		...t
	};
}
function $(e, t) {
	return e.replace(/\{(\w+)\}/g, (e, n) => {
		let r = t[n];
		return r === void 0 ? e : String(r);
	});
}
function Qe(e) {
	let t = Math.floor(e / 1e3), n = Math.floor(t / 3600), r = Math.floor(t % 3600 / 60), i = t % 60, a = String(r).padStart(n > 0 ? 2 : 1, "0"), o = String(i).padStart(2, "0");
	return n > 0 ? `${n}:${a}:${o}` : `${a}:${o}`;
}
// src/styles.ts
var $e = "\n:host {\n  --ipp-font: system-ui, sans-serif;\n  --ipp-fg: #1d232b;\n  --ipp-muted: #55606e;\n  --ipp-bg: #ffffff;\n  --ipp-surface: #eef1f5;\n  --ipp-border: #c4ccd6;\n  --ipp-accent: #1f5fbf;\n  --ipp-accent-fg: #ffffff;\n  --ipp-focus: #d1006a;\n  --ipp-correct: #1b7f3b;\n  --ipp-hint: #b35c00;\n  --ipp-radius: 6px;\n  --ipp-gap: 2px;\n  --ipp-tray: #dde3ea;\n  --ipp-duration: 160ms;\n  display: block;\n  color: var(--ipp-fg);\n  font-family: var(--ipp-font);\n  container-type: inline-size;\n}\n:host([hidden]) { display: none; }\n* { box-sizing: border-box; }\n.root { display: grid; gap: 0.5rem; background: var(--ipp-bg); }\n.toolbar { display: flex; flex-wrap: wrap; gap: 0.25rem; }\n.toolbar[hidden] { display: none; }\nbutton.control {\n  font: inherit;\n  font-size: 0.875rem;\n  min-height: 2.75rem;\n  min-width: 2.75rem;\n  padding: 0.25rem 0.75rem;\n  border: 1px solid var(--ipp-border);\n  border-radius: var(--ipp-radius);\n  background: var(--ipp-surface);\n  color: var(--ipp-fg);\n  cursor: pointer;\n}\nbutton.control[aria-pressed='true'] { background: var(--ipp-accent); color: var(--ipp-accent-fg); border-color: var(--ipp-accent); }\nbutton.control:disabled { opacity: 0.5; cursor: not-allowed; }\nbutton:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }\n.status { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; color: var(--ipp-muted); font-size: 0.875rem; font-variant-numeric: tabular-nums; }\n.message { margin: 0; padding: 1rem; border: 1px dashed var(--ipp-border); border-radius: var(--ipp-radius); }\n.message[data-kind='error'] { border-style: solid; border-color: #b00020; color: #b00020; }\n.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }\n\n.board { position: relative; width: 100%; direction: ltr; background: var(--ipp-surface); border-radius: var(--ipp-radius); overflow: hidden; touch-action: manipulation; user-select: none; -webkit-user-select: none; }\n.board[data-loading='true'] .tile, .board[data-loading='true'] .piece { visibility: hidden; }\n.tile {\n  position: absolute;\n  padding: 0;\n  margin: 0;\n  border: 0;\n  border-radius: 0;\n  background-color: var(--ipp-surface);\n  background-repeat: no-repeat;\n  box-shadow: inset 0 0 0 calc(var(--ipp-gap) / 2) var(--ipp-bg);\n  cursor: pointer;\n  transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease, transform var(--ipp-duration) ease;\n  touch-action: none;\n}\n.tile:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: -3px; z-index: 3; }\n.tile[aria-pressed='true'] { box-shadow: inset 0 0 0 4px var(--ipp-accent); z-index: 2; }\n.tile.is-correct::after { content: ''; position: absolute; inset: auto 4px 4px auto; width: 10px; height: 10px; border-radius: 50%; background: var(--ipp-correct); box-shadow: 0 0 0 2px var(--ipp-bg); }\n.tile.is-hint, .piece.is-hint .outline { animation: ipp-hint 1s ease 2; }\n.tile.is-hint { box-shadow: inset 0 0 0 4px var(--ipp-hint); }\n.tile.is-dragging { transition: none; z-index: 4; opacity: 0.9; }\n.tile.is-target { box-shadow: inset 0 0 0 4px var(--ipp-accent); }\n.number { position: absolute; inset: 4px auto auto 4px; padding: 0 0.3em; border-radius: 3px; background: rgb(0 0 0 / 0.6); color: #fff; font-size: 0.75rem; line-height: 1.4; pointer-events: none; }\n.marker { position: absolute; background: var(--ipp-accent); pointer-events: none; z-index: 5; }\n.preview { position: absolute; background-repeat: no-repeat; pointer-events: none; z-index: 6; opacity: 0.85; }\n.preview[hidden] { display: none; }\n\n.viewport { position: relative; width: 100%; overflow: auto; border-radius: var(--ipp-radius); background: var(--ipp-tray); overscroll-behavior: contain; }\n.stage { position: relative; background: var(--ipp-tray); }\n.stage .board-area { position: absolute; background: var(--ipp-surface); outline: 1px solid var(--ipp-border); }\n.stage .guides { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }\n.stage .guides path { fill: none; stroke: var(--ipp-border); stroke-width: 1; vector-effect: non-scaling-stroke; }\n.piece { position: absolute; padding: 0; margin: 0; border: 0; background: none; pointer-events: none; transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease; }\n.piece:focus-visible { outline: none; }\n.piece img { display: block; width: 100%; height: 100%; pointer-events: none; -webkit-user-drag: none; }\n.piece svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }\n.piece .outline { fill: transparent; stroke: none; pointer-events: all; cursor: grab; touch-action: none; vector-effect: non-scaling-stroke; }\n.piece:focus-visible .outline { stroke: var(--ipp-focus); stroke-width: 3; }\n.piece.is-dragging { transition: none; }\n.piece.is-dragging .outline { cursor: grabbing; }\n.piece.is-placed .outline { pointer-events: none; cursor: default; }\n.piece.is-placed.highlight .outline { stroke: var(--ipp-correct); stroke-width: 1; }\n.piece.is-hint .outline { stroke: var(--ipp-hint); stroke-width: 3; }\n\n@keyframes ipp-hint { 50% { opacity: 0.5; } }\n@media (prefers-reduced-motion: reduce) {\n  :host(:not([data-motion='full'])) .tile, :host(:not([data-motion='full'])) .piece { transition: none; }\n  :host(:not([data-motion='full'])) .is-hint, :host(:not([data-motion='full'])) .is-hint .outline { animation: none; }\n}\n:host([data-motion='reduce']) .tile, :host([data-motion='reduce']) .piece { transition: none; }\n:host([data-motion='reduce']) .is-hint, :host([data-motion='reduce']) .is-hint .outline { animation: none; }\n@media (forced-colors: active) {\n  .tile:focus-visible, button:focus-visible { outline-color: Highlight; }\n  .piece:focus-visible .outline { stroke: Highlight; }\n}\n";
// src/views/media.ts
function et(e, t, n) {
	let r = (e, t, n) => n === t ? "0%" : Q(e / (n - t) * 100);
	return {
		backgroundImage: qe(e),
		backgroundSize: `${Q(n.width / t.width * 100)} ${Q(n.height / t.height * 100)}`,
		backgroundPosition: `${r(t.x, t.width, n.width)} ${r(t.y, t.height, n.height)}`
	};
}
function tt(e, t, n) {
	let r = new Image(), i = !1;
	return r.onload = () => {
		i || (i = !0, t());
	}, r.onerror = () => {
		i || (i = !0, n());
	}, r.decoding = "async", r.src = e, { cancel() {
		i = !0, r.onload = null, r.onerror = null, r.removeAttribute("src");
	} };
}
// src/views/grid.ts
var nt = 6;
function rt(e, t, n) {
	let r = /* @__PURE__ */ new Map();
	if (e === "rotation" && "orientations" in n) return t.forEach((e, t) => r.set(e.id, n.orientations[t] ?? 0)), {
		cells: t.map((e) => e.id),
		orientation: r
	};
	if (e === "strips" && "order" in n) return {
		cells: n.order,
		orientation: r
	};
	if ("cells" in n) return {
		cells: n.cells,
		orientation: r
	};
	throw Error(`Board does not match mode ${e}`);
}
function it(e) {
	let { doc: t, definition: n } = e, { kind: r, rows: i, cols: a, crop: o, source: s } = n, c = new Map(n.tiles.map((e) => [e.id, e])), l = e.asset(n.media.image), u = `ipp-instructions-${Math.random().toString(36).slice(2, 10)}`, d = Z(t, "div", { class: "board-wrap" }), f = Z(t, "p", {
		class: "sr-only",
		id: u
	}, e.messages().instructions[r] ?? ""), p = Z(t, "div", {
		class: "board",
		part: "board",
		role: "group",
		"aria-label": e.messages().label,
		"aria-describedby": u,
		"data-kind": r,
		"data-loading": "true"
	});
	p.style.aspectRatio = `${o.width} / ${o.height}`, d.append(f, p);
	let m = r === "sliding" ? n.rules.empty : void 0, h = /* @__PURE__ */ new Map();
	for (let e of n.tiles) {
		if (e.id === m) continue;
		let r = Z(t, "button", {
			type: "button",
			class: "tile",
			part: "tile",
			tabindex: "-1",
			"data-tile": e.id
		});
		Object.assign(r.style, et(l, e.rect, s), {
			width: Q(100 / a),
			height: Q(100 / i)
		}), n.assistance.numbers && r.append(Z(t, "span", {
			class: "number",
			"aria-hidden": "true"
		}, String(e.home + 1))), h.set(e.id, r), p.append(r);
	}
	let g = Z(t, "div", {
		class: "marker",
		hidden: ""
	}), _ = Z(t, "div", {
		class: "preview",
		part: "preview",
		hidden: ""
	});
	Object.assign(_.style, et(l, o, s), { inset: "0" }), p.append(g, _);
	let v = tt(l, () => {
		p.setAttribute("data-loading", "false");
	}, () => {
		e.assetError(l);
	}), y = rt(r, n.tiles, e.engine.exportState().board), b = n.tiles[0]?.id ?? "", x = null, S = null, C = /* @__PURE__ */ new Map(), w = null, T = null, E = !1, D = (e) => y.cells.indexOf(e), O = (e) => [Math.floor(e / a), e % a], k = (e, t) => r === "rotation" ? (y.orientation.get(e.id) ?? 0) === 0 : t === e.home;
	function A(t, n) {
		let i = e.messages(), [a, o] = O(n), s = r === "strips" ? $(i.strip, {
			n: t.home + 1,
			position: n + 1,
			count: y.cells.length
		}) : $(i.tile, {
			n: t.home + 1,
			row: a + 1,
			col: o + 1
		});
		return r === "rotation" && (s += `, ${$(i.orientation, { degrees: y.orientation.get(t.id) ?? 0 })}`), s;
	}
	function j() {
		(!h.has(b) || D(b) < 0) && (b = y.cells.find((e) => e !== null) ?? b);
		for (let [t, o] of h) {
			let s = c.get(t), l = D(t), [u, d] = O(l);
			if (o.style.left = Q(d * 100 / a), o.style.top = Q(u * 100 / i), r === "rotation") {
				let e = y.orientation.get(t) ?? 0, n = C.get(t) ?? e, r = ((e - n) % 360 + 540) % 360 - 180;
				C.set(t, n + r), o.style.transform = `rotate(${n + r}deg)`;
			}
			o.setAttribute("aria-label", A(s, l)), o.tabIndex = t === b ? 0 : -1, (r === "swap" || r === "strips") && o.setAttribute("aria-pressed", String(x === t)), o.classList.toggle("is-correct", n.assistance.highlightCorrect && k(s, l)), o.disabled = e.engine.status === "complete";
		}
		M();
	}
	function M() {
		if (r !== "strips" || S === null || x === null) {
			g.hidden = !0;
			return;
		}
		g.hidden = !1;
		let e = D(x), t = S > e ? S + 1 : S;
		i > 1 ? Object.assign(g.style, {
			left: "0",
			width: "100%",
			height: "4px",
			top: `calc(${Q(t * 100 / i)} - 2px)`
		}) : Object.assign(g.style, {
			top: "0",
			height: "100%",
			width: "4px",
			left: `calc(${Q(t * 100 / a)} - 2px)`
		});
	}
	function N() {
		x = null, S = null;
	}
	function P(t, n) {
		e.dispatch(t) ? e.announce(n) : e.announce(e.messages().illegal);
	}
	function F(t, n) {
		let i = c.get(t);
		if (i === void 0 || e.engine.status === "complete") return;
		let a = e.messages(), o = i.home + 1;
		if (r === "sliding") P({
			type: "slide",
			tile: t
		}, $(a.moved, { n: o }));
		else if (r === "rotation") {
			let r = e.dispatch({
				type: "rotate",
				tile: t,
				direction: n ? "ccw" : "cw"
			});
			e.announce(r ? $(a.rotated, {
				n: o,
				degrees: y.orientation.get(t) ?? 0
			}) : a.illegal);
		} else if (x === null) x = t, S = D(t), j(), e.announce($(a.tileSelected, { n: o }));
		else if (x === t && (r === "swap" || S === D(t))) N(), j();
		else if (r === "swap") {
			let e = c.get(x);
			N(), P({
				type: "swap",
				a: e.id,
				b: t
			}, $(a.swapped, {
				a: e.home + 1,
				b: o
			}));
		} else {
			let e = c.get(x), n = x === t ? S ?? D(t) : D(t);
			N(), P({
				type: "move",
				strip: e.id,
				to: n
			}, $(a.moved, { n: e.home + 1 }));
		}
	}
	function I(e, t) {
		let [n, r] = O(e), o = {
			ArrowUp: [-1, 0],
			ArrowDown: [1, 0],
			ArrowLeft: [0, -1],
			ArrowRight: [0, 1]
		}[t];
		if (o === void 0) return null;
		let [s, c] = [n + o[0], r + o[1]];
		return s >= 0 && s < i && c >= 0 && c < a && y.cells[s * a + c] === null && ([s, c] = [s + o[0], c + o[1]]), s >= 0 && s < i && c >= 0 && c < a ? s * a + c : null;
	}
	function L(t) {
		let n = t.key;
		if (b = B(t) ?? b, n === "Enter" || n === " ") {
			t.preventDefault(), F(b, t.shiftKey);
			return;
		}
		if (n === "Escape" && x !== null) {
			t.preventDefault(), N(), j();
			return;
		}
		if (n === "Home" || n === "End") {
			t.preventDefault();
			let e = y.cells.filter((e) => e !== null);
			R((n === "Home" ? e[0] : e[e.length - 1]) ?? b);
			return;
		}
		if (r === "strips" && x !== null && S !== null) {
			let r = I(S, n);
			(r !== null || n.startsWith("Arrow")) && t.preventDefault(), r !== null && (S = r, M(), e.announce($(e.messages().strip, {
				n: (c.get(x)?.home ?? 0) + 1,
				position: r + 1,
				count: y.cells.length
			})));
			return;
		}
		let i = I(D(b), n);
		n.startsWith("Arrow") && t.preventDefault();
		let a = i === null ? null : y.cells[i];
		a != null && R(a);
	}
	function R(e) {
		b = e, j(), h.get(e)?.focus();
	}
	function z(e, t) {
		let n = p.getBoundingClientRect(), r = Math.floor((e - n.left) / n.width * a), o = Math.floor((t - n.top) / n.height * i);
		return o >= 0 && o < i && r >= 0 && r < a ? o * a + r : null;
	}
	function B(e) {
		let t = e.target?.closest("button.tile");
		return t instanceof HTMLElement && p.contains(t) ? t.dataset.tile ?? null : null;
	}
	function V(e) {
		let t = B(e);
		t === null || e.button !== 0 || r !== "swap" && r !== "strips" || (T = {
			id: t,
			pointer: e.pointerId,
			x: e.clientX,
			y: e.clientY,
			active: !1
		});
	}
	function H(e) {
		if (T === null || e.pointerId !== T.pointer) return;
		let t = e.clientX - T.x, n = e.clientY - T.y, a = h.get(T.id);
		if (a === void 0) return;
		if (!T.active) {
			if (Math.hypot(t, n) < nt) return;
			T.active = !0, a.setPointerCapture(e.pointerId), a.classList.add("is-dragging"), N();
		}
		a.style.transform = r === "strips" ? i > 1 ? `translateY(${n}px)` : `translateX(${t}px)` : `translate(${t}px, ${n}px)`;
		let o = z(e.clientX, e.clientY);
		for (let [e, t] of h) t.classList.toggle("is-target", r === "swap" && o !== null && y.cells[o] === e && e !== T.id);
		r === "strips" && (x = T.id, S = o, M(), x = null);
	}
	function U(t, n) {
		if (T === null || t.pointerId !== T.pointer) return;
		let { id: i, active: a } = T;
		if (T = null, !a) return;
		E = !0;
		let o = h.get(i);
		o?.classList.remove("is-dragging"), o !== void 0 && (o.style.transform = "");
		for (let e of h.values()) e.classList.remove("is-target");
		g.hidden = !0, S = null;
		let s = n ? z(t.clientX, t.clientY) : null, l = c.get(i), u = e.messages();
		if (s !== null && s !== D(i)) {
			if (r === "swap") {
				let e = c.get(y.cells[s] ?? "");
				e !== void 0 && P({
					type: "swap",
					a: i,
					b: e.id
				}, $(u.swapped, {
					a: l.home + 1,
					b: e.home + 1
				}));
			} else P({
				type: "move",
				strip: i,
				to: s
			}, $(u.moved, { n: l.home + 1 }));
		}
	}
	function ee(e) {
		if (E) {
			E = !1;
			return;
		}
		let t = B(e);
		t !== null && (b = t, F(t, e.shiftKey));
	}
	let W = (e) => {
		U(e, !0);
	}, G = (e) => {
		U(e, !1);
	};
	return p.addEventListener("keydown", L), p.addEventListener("click", ee), p.addEventListener("pointerdown", V), p.addEventListener("pointermove", H), p.addEventListener("pointerup", W), p.addEventListener("pointercancel", G), j(), {
		element: d,
		update(e, t) {
			y = rt(r, n.tiles, e), t && N(), j();
		},
		showHint(e) {
			let t = "tile" in e ? e.tile : "strip" in e ? e.strip : "a" in e ? e.a : null, n = t === null ? void 0 : h.get(t);
			if (n !== void 0) {
				for (let e of h.values()) e.classList.remove("is-hint");
				n.classList.add("is-hint"), w !== null && clearTimeout(w), w = setTimeout(() => {
					n.classList.remove("is-hint");
				}, 2500), t !== null && R(t);
			}
		},
		setPreview(e) {
			_.hidden = !e;
		},
		focus() {
			h.get(b)?.focus();
		},
		destroy() {
			w !== null && clearTimeout(w), v.cancel(), p.removeEventListener("keydown", L), p.removeEventListener("click", ee), p.removeEventListener("pointerdown", V), p.removeEventListener("pointermove", H), p.removeEventListener("pointerup", W), p.removeEventListener("pointercancel", G), d.remove();
		}
	};
}
// src/views/jigsaw.ts
var at = "http://www.w3.org/2000/svg", ot = 4;
function st(e) {
	let { doc: t, definition: n } = e, r = F(n), i = n.crop, a = Math.min(...n.tiles.map((e) => Math.min(e.rect.width, e.rect.height))), o = Math.max(1, Math.round(a / 8)), s = `ipp-instructions-${Math.random().toString(36).slice(2, 10)}`, c = Z(t, "div", { class: "board-wrap" }), l = Z(t, "p", {
		class: "sr-only",
		id: s
	}, e.messages().instructions.jigsaw ?? ""), u = Z(t, "div", {
		class: "viewport",
		part: "viewport"
	}), d = Z(t, "div", {
		class: "stage board",
		part: "board",
		role: "group",
		"aria-label": e.messages().label,
		"aria-describedby": s,
		"data-kind": "jigsaw",
		"data-loading": "true"
	});
	d.style.aspectRatio = `${r.width} / ${r.height}`, d.style.width = "100%", u.append(d), c.append(l, u);
	let f = (e) => ({
		left: Q((e.x - r.x) / r.width * 100),
		top: Q((e.y - r.y) / r.height * 100),
		width: Q(e.width / r.width * 100),
		height: Q(e.height / r.height * 100)
	}), p = Z(t, "div", {
		class: "board-area",
		part: "board-area"
	});
	Object.assign(p.style, f(i));
	let m = t.createElementNS(at, "svg");
	m.setAttribute("class", "guides"), m.setAttribute("viewBox", `${r.x} ${r.y} ${r.width} ${r.height}`), m.setAttribute("preserveAspectRatio", "none"), m.setAttribute("aria-hidden", "true");
	let h = Z(t, "div", {
		class: "preview",
		part: "preview",
		hidden: ""
	});
	Object.assign(h.style, et(e.asset(n.media.image), i, n.source), f(i), { opacity: "0.4" }), d.append(p, m, h);
	let g = 0, _ = !1, v = n.tiles.map((n) => {
		let i = N(n), a = (typeof n.attributes.path == "string" ? n.attributes.path : null) ?? `M${i.x} ${i.y} L${i.x + i.width} ${i.y} L${i.x + i.width} ${i.y + i.height} L${i.x} ${i.y + i.height} Z`, o = t.createElementNS(at, "path");
		o.setAttribute("d", a), m.append(o);
		let s = Z(t, "button", {
			type: "button",
			class: "piece",
			part: "piece",
			"data-piece": n.id
		});
		Object.assign(s.style, {
			width: Q(i.width / r.width * 100),
			height: Q(i.height / r.height * 100)
		});
		let c = e.asset(String(n.attributes.image)), l = Z(t, "img", {
			alt: "",
			draggable: "false",
			decoding: "async"
		});
		g++, l.addEventListener("load", b, { once: !0 }), l.addEventListener("error", () => {
			x(c);
		}, { once: !0 }), l.src = c;
		let u = t.createElementNS(at, "svg");
		u.setAttribute("viewBox", `${i.x} ${i.y} ${i.width} ${i.height}`), u.setAttribute("preserveAspectRatio", "none"), u.setAttribute("aria-hidden", "true");
		let f = t.createElementNS(at, "path");
		return f.setAttribute("class", "outline"), f.setAttribute("d", a), u.append(f), s.append(l, u), d.append(s), {
			tile: n,
			rendered: i,
			button: s,
			image: l
		};
	}), y = new Map(v.map((e) => [e.tile.id, e]));
	function b() {
		g--, g === 0 && !_ && d.setAttribute("data-loading", "false");
	}
	function x(t) {
		_ || (_ = !0, e.assetError(t));
	}
	let S = O(e.engine.exportState().board), C = /* @__PURE__ */ new Map(), w = 1, T = null, E = null;
	function D(e) {
		typeof e.scrollIntoView == "function" && e.scrollIntoView({
			block: "nearest",
			inline: "nearest"
		});
	}
	function O(e) {
		if (!("pieces" in e)) throw Error("Board does not match the jigsaw mode");
		return e.pieces;
	}
	function k(e, t, n) {
		return {
			x: Math.round(Math.min(Math.max(t, r.x), r.x + r.width - e.rendered.width)),
			y: Math.round(Math.min(Math.max(n, r.y), r.y + r.height - e.rendered.height))
		};
	}
	function A(e, t, n) {
		e.button.style.left = Q((t - r.x) / r.width * 100), e.button.style.top = Q((n - r.y) / r.height * 100);
	}
	function j() {
		let t = e.engine.status === "complete", r = e.messages();
		v.forEach((e, i) => {
			let a = S[i];
			if (a === void 0) return;
			let o = C.get(e.tile.id) ?? a;
			A(e, o.x, o.y), e.button.classList.toggle("is-placed", a.placed), e.button.classList.toggle("highlight", a.placed && n.assistance.highlightCorrect), e.button.tabIndex = a.placed || t ? -1 : 0, e.button.setAttribute("aria-disabled", String(a.placed || t)), e.button.setAttribute("aria-label", $(a.placed ? r.piecePlaced : r.piece, { n: e.tile.home + 1 })), a.placed && (e.button.style.zIndex = "0");
		});
	}
	function M(e) {
		e.button.style.zIndex = String(++w);
	}
	function P(t, r, i) {
		C.delete(t.tile.id);
		let a = e.messages(), o = e.dispatch({
			type: "place",
			piece: t.tile.id,
			x: r,
			y: i
		}), s = n.tiles.indexOf(t.tile);
		if (!o) {
			e.announce(a.illegal), j();
			return;
		}
		let c = S[s]?.placed ?? !1;
		e.announce($(c ? a.placed : a.dropped, { n: t.tile.home + 1 })), c && I(s);
	}
	function I(e) {
		for (let t = 1; t <= v.length; t++) {
			let n = (e + t) % v.length;
			if (S[n]?.placed === !1) {
				v[n]?.button.focus();
				return;
			}
		}
	}
	function L(e) {
		let t = e.target?.closest("button.piece");
		return t instanceof HTMLElement && d.contains(t) ? y.get(t.dataset.piece ?? "") : void 0;
	}
	function R(t) {
		let r = L(t);
		if (r === void 0) return;
		let i = n.tiles.indexOf(r.tile), a = S[i];
		if (a === void 0 || a.placed || e.engine.status === "complete") return;
		let s = C.get(r.tile.id) ?? a, c = t.shiftKey ? o * 5 : o, l = {
			ArrowUp: [0, -1],
			ArrowDown: [0, 1],
			ArrowLeft: [-1, 0],
			ArrowRight: [1, 0]
		}[t.key];
		if (l !== void 0) {
			t.preventDefault();
			let e = k(r, s.x + l[0] * c, s.y + l[1] * c);
			C.set(r.tile.id, e), M(r), A(r, e.x, e.y), D(r.button);
		} else t.key === "Enter" || t.key === " " ? (t.preventDefault(), C.has(r.tile.id) && P(r, s.x, s.y)) : t.key === "Escape" && C.has(r.tile.id) && (t.preventDefault(), C.delete(r.tile.id), j());
	}
	function z(t) {
		let i = L(t);
		if (i === void 0 || t.button !== 0 || e.engine.status === "complete") return;
		let a = n.tiles.indexOf(i.tile), o = S[a];
		if (o === void 0 || o.placed) return;
		t.preventDefault();
		let s = C.get(i.tile.id) ?? o, c = r.width / Math.max(1, d.getBoundingClientRect().width);
		E = {
			id: i.tile.id,
			pointer: t.pointerId,
			startX: t.clientX,
			startY: t.clientY,
			fromX: s.x,
			fromY: s.y,
			scale: c,
			active: !1
		}, d.setPointerCapture(t.pointerId), M(i), i.button.focus({ preventScroll: !0 });
	}
	function B(e) {
		if (E === null || e.pointerId !== E.pointer) return;
		let t = y.get(E.id), n = e.clientX - E.startX, r = e.clientY - E.startY;
		if (!E.active && Math.hypot(n, r) < ot) return;
		E.active = !0, t.button.classList.add("is-dragging");
		let i = k(t, E.fromX + n * E.scale, E.fromY + r * E.scale);
		C.set(t.tile.id, i), A(t, i.x, i.y);
	}
	function V(e) {
		if (E === null || e.pointerId !== E.pointer) return;
		let { id: t, active: n } = E;
		E = null, d.hasPointerCapture(e.pointerId) && d.releasePointerCapture(e.pointerId);
		let r = y.get(t);
		r.button.classList.remove("is-dragging");
		let i = C.get(t);
		!n || i === void 0 || e.type === "pointercancel" ? (C.delete(t), j()) : P(r, i.x, i.y);
	}
	return d.addEventListener("keydown", R), d.addEventListener("pointerdown", z), d.addEventListener("pointermove", B), d.addEventListener("pointerup", V), d.addEventListener("pointercancel", V), j(), {
		element: c,
		update(e, t) {
			S = O(e), t && C.clear(), j();
		},
		showHint(e) {
			if (e.type !== "place") return;
			let t = y.get(e.piece);
			if (t !== void 0) {
				for (let e of v) e.button.classList.remove("is-hint");
				t.button.classList.add("is-hint"), M(t), t.button.focus(), D(t.button), T !== null && clearTimeout(T), T = setTimeout(() => {
					t.button.classList.remove("is-hint");
				}, 2500);
			}
		},
		setPreview(e) {
			h.hidden = !e;
		},
		setZoom(e) {
			d.style.width = Q(Math.max(1, e) * 100);
		},
		focus() {
			let e = S.findIndex((e) => !e.placed);
			(v[e] ?? v[0])?.button.focus();
		},
		destroy() {
			T !== null && clearTimeout(T), d.removeEventListener("keydown", R), d.removeEventListener("pointerdown", z), d.removeEventListener("pointermove", B), d.removeEventListener("pointerup", V), d.removeEventListener("pointercancel", V);
			for (let e of v) e.image.removeAttribute("src");
			c.remove();
		}
	};
}
// src/player.ts
var ct = 4194304, lt = [
	1,
	1.5,
	2,
	3,
	4
], ut = /* @__PURE__ */ new WeakMap();
function dt(e) {
	return e.baseURI;
}
function ft(e, t = {}) {
	if (ut.has(e)) throw new o("invalid", "This element already hosts an image puzzle player; destroy it first.");
	let n = e.ownerDocument, r, i, a = !(e instanceof ShadowRoot);
	e instanceof ShadowRoot ? (r = e, i = e.host) : (i = Z(n, "div", { class: "image-puzzle-player" }), e.append(i), r = i.attachShadow({ mode: "open" }));
	let s = t.locale, c = Ze(s, t.messages), l = t.onListenerError ?? ((e) => {
		queueMicrotask(() => {
			throw e;
		});
	}), u = Re({
		...t.clock ? { clock: t.clock } : {},
		...t.randomSeed ? { randomSeed: t.randomSeed } : {},
		onListenerError: l
	}), d = t.fetch ?? ((e, t) => fetch(e, t)), f = t.persist === void 0 || t.persist === !1 ? null : t.persist === !0 ? {} : t.persist, p = f === null ? null : f.storage === void 0 ? Be() : f.storage, m = f?.prefix, h = Z(n, "style", {}, $e), g = Z(n, "div", {
		class: "root",
		part: "root"
	}), _ = Z(n, "div", {
		class: "toolbar",
		part: "toolbar",
		role: "group"
	}), v = (e) => Z(n, "button", {
		type: "button",
		class: "control",
		part: `control control-${e}`,
		"data-control": e
	}), y = {
		start: v("start"),
		undo: v("undo"),
		redo: v("redo"),
		reset: v("reset"),
		shuffle: v("shuffle"),
		hint: v("hint"),
		preview: v("preview"),
		zoomOut: v("zoom-out"),
		zoomIn: v("zoom-in")
	};
	_.append(...Object.values(y)), _.hidden = t.controls === !1;
	let b = Z(n, "div", {
		class: "status",
		part: "status"
	}), x = Z(n, "span", { "data-status": "moves" }), S = Z(n, "span", { "data-status": "time" }), C = Z(n, "span", { "data-status": "progress" }), w = Z(n, "span", { "data-status": "hints" });
	b.append(x, S, C, w);
	let T = Z(n, "p", {
		class: "message",
		part: "message",
		role: "status"
	}), E = Z(n, "div", { class: "slot" }), D = Z(n, "div", {
		class: "sr-only",
		"aria-live": "polite",
		"aria-atomic": "true"
	});
	g.append(_, b, T, E, D), r.replaceChildren(h, g);
	let O = /* @__PURE__ */ new Map(), k = null, A = "empty", j = t.baseUrl ?? dt(n), M = 0, N = null, P = null, F = !1, I = 0, L = !1, R = !1, z = !1, B = p === null ? null : We(u, p, V());
	function V() {
		return {
			...m === void 0 ? {} : { prefix: m },
			...f?.debounceMs === void 0 ? {} : { debounceMs: f.debounceMs },
			onError: (e) => {
				H("error", {
					code: e.code,
					message: e.message
				});
			}
		};
	}
	function H(e, t) {
		for (let n of [...O.get(e) ?? []]) try {
			n(t);
		} catch (e) {
			l(e);
		}
	}
	function U(e) {
		e !== "" && (D.textContent = "", D.textContent = e);
	}
	function ee() {
		(t.motion === "reduce" || t.motion === "full") && i.setAttribute("data-motion", t.motion);
	}
	function W(e, t = "") {
		T.hidden = e === null, T.dataset.kind = e ?? "", T.textContent = t;
	}
	function G(e, t = "") {
		A = e, i.setAttribute("data-state", e), g.setAttribute("aria-busy", String(e === "loading"));
		let n = c;
		e === "empty" ? W("empty", n.empty) : e === "loading" ? W("loading", n.loading) : e === "error" ? W("error", $(n.loadError, { message: t })) : W(null), E.hidden = e === "loading" || k === null, te();
	}
	function K() {
		let e = c;
		s === void 0 ? g.removeAttribute("lang") : g.lang = s, _.setAttribute("aria-label", e.controls), y.undo.textContent = e.undo, y.redo.textContent = e.redo, y.reset.textContent = e.reset, y.shuffle.textContent = e.shuffle, y.hint.textContent = e.hint, y.preview.textContent = e.preview, y.zoomOut.textContent = e.zoomOut, y.zoomIn.textContent = e.zoomIn;
	}
	function te() {
		let e = u.status === "destroyed" ? null : u.definition, t = e !== null && A !== "loading", n = u.status, r = e?.capabilities;
		y.start.hidden = !t, y.start.textContent = n === "running" ? c.pause : n === "paused" ? c.resume : c.start, y.start.disabled = n === "complete", y.undo.hidden = !t || r?.undo !== !0, y.undo.disabled = !t || !u.canUndo(), y.redo.hidden = !t || r?.redo !== !0, y.redo.disabled = !t || !u.canRedo(), y.reset.hidden = !t || r?.reset !== !0, y.shuffle.hidden = !t || r?.shuffle !== !0, y.hint.hidden = !t || r?.hint !== !0, y.hint.disabled = n === "complete", y.preview.hidden = !t || !e.assistance.preview, y.preview.setAttribute("aria-pressed", String(F));
		let i = t && e.kind === "jigsaw";
		y.zoomOut.hidden = !i, y.zoomIn.hidden = !i, y.zoomOut.disabled = I === 0, y.zoomIn.disabled = I === lt.length - 1, b.hidden = !t, ne();
	}
	function ne() {
		if (u.status === "destroyed" || u.definition === null) return;
		let e = u.snapshot();
		x.textContent = $(c.moves, { n: e.moves }), S.textContent = $(c.time, { time: Qe(e.elapsedMs) }), C.textContent = $(c.progress, { percent: Math.floor(e.progress * 100) }), w.hidden = !u.definition.capabilities.hint, w.textContent = $(c.hints, { n: e.hintsUsed });
	}
	function re() {
		let e = u.status === "running" && !R;
		e && P === null ? P = setInterval(ne, 1e3) : !e && P !== null && (clearInterval(P), P = null);
	}
	function q() {
		k?.destroy(), k = null, F = !1, I = 0;
		let e = u.definition;
		if (e === null) return;
		let t = {
			doc: n,
			definition: e,
			engine: u,
			messages: () => c,
			asset: (e) => Ke(e, j),
			announce: U,
			dispatch: (e) => u.dispatchAction(e).ok,
			assetError: (e) => {
				H("error", {
					code: "load",
					message: `Could not load ${e}`
				}), G("error", `Could not load ${e}`);
			}
		};
		k = e.kind === "jigsaw" ? st(t) : it(t), E.replaceChildren(k.element);
	}
	let ie = [
		u.subscribe("state-change", (e) => {
			e.reason === "load" ? q() : k?.update(e.state.board, e.reason !== "action"), te(), re(), H("state-change", e);
		}),
		u.subscribe("complete", (e) => {
			U($(c.complete, {
				moves: e.moves,
				time: Qe(e.elapsedMs)
			})), H("complete", e);
		}),
		u.subscribe("ready", (e) => {
			H("ready", e);
		}),
		u.subscribe("move", (e) => {
			H("move", e);
		}),
		u.subscribe("progress", (e) => {
			H("progress", e);
		}),
		u.subscribe("error", (e) => {
			H("error", e);
		})
	];
	function ae(e) {
		let t = e.target?.closest("button[data-control]");
		if (t instanceof HTMLButtonElement && !t.disabled && u.definition !== null) switch (t.dataset.control) {
			case "start":
				u.status === "running" ? u.pause() : u.start();
				break;
			case "undo":
				u.undo();
				break;
			case "redo":
				u.redo();
				break;
			case "reset":
				u.reset();
				break;
			case "shuffle":
				u.shuffle();
				break;
			case "hint":
				le.hint();
				break;
			case "preview":
				F = !F, k?.setPreview(F), te();
				break;
			case "zoom-in":
			case "zoom-out": I = Math.min(lt.length - 1, Math.max(0, I + (t.dataset.control === "zoom-in" ? 1 : -1))), k?.setZoom?.(lt[I] ?? 1), te();
		}
	}
	_.addEventListener("click", ae);
	function oe() {
		n.visibilityState === "hidden" && u.status === "running" ? (u.pause(), z = !0) : n.visibilityState === "visible" && z && (z = !1, u.status === "paused" && u.start());
	}
	let J = t.pauseWhenHidden !== !1;
	J && n.addEventListener("visibilitychange", oe);
	async function se(e, t) {
		let n;
		try {
			n = await d(e, {
				signal: t,
				credentials: "same-origin",
				headers: { Accept: "application/json" }
			});
		} catch (n) {
			throw t.aborted ? n : new o("load", `Could not fetch ${e}`, { cause: n });
		}
		if (!n.ok) throw new o("load", `Fetching ${e} failed with HTTP ${n.status}`);
		if (Number(n.headers.get("content-length") ?? "0") > 4194304) throw new o("load", `${e} exceeds ${ct} bytes`);
		let r = await n.text();
		if (r.length > 4194304) throw new o("load", `${e} exceeds ${ct} bytes`);
		try {
			return {
				data: JSON.parse(r),
				base: n.url === "" ? e : n.url
			};
		} catch (t) {
			throw new o("load", `${e} is not valid JSON`, { cause: t });
		}
	}
	function ce(e) {
		return e !== M || L;
	}
	async function Y(e, r = {}) {
		if (L) throw new o("destroyed", "The player was destroyed.");
		let i = ++M;
		N?.abort();
		let a = new AbortController();
		N = a, G("loading");
		let s, c;
		try {
			if (typeof e == "string") {
				let t = new URL(e, dt(n)).href;
				({data: s, base: c} = await se(t, a.signal));
			} else s = e, c = r.baseUrl ?? t.baseUrl ?? dt(n);
		} catch (e) {
			if (ce(i)) return;
			let t = e instanceof o ? e : new o("load", String(e), { cause: e });
			throw H("error", {
				code: t.code,
				message: t.message
			}), G("error", t.message), t;
		}
		if (ce(i)) return;
		N = null;
		let l = j;
		j = c;
		try {
			u.load(s, r.state);
		} catch (e) {
			throw j = l, G("error", e instanceof Error ? e.message : String(e)), e;
		}
		if (G("ready"), r.state === void 0 && p !== null) {
			let e = Ue(u, p, m);
			!e.restored && e.error?.code === "storage" && H("error", {
				code: e.error.code,
				message: e.error.message
			});
		}
	}
	let le = {
		element: i,
		engine: u,
		get view() {
			return A;
		},
		load: Y,
		start: () => {
			u.start();
		},
		pause: () => {
			u.pause();
		},
		reset: () => {
			u.reset();
		},
		shuffle: (e) => {
			u.shuffle(e);
		},
		undo: () => u.undo(),
		redo: () => u.redo(),
		hint() {
			let e = u.hint();
			return e === null ? U(c.noHint) : k?.showHint(e), ne(), e;
		},
		dispatchAction: (e) => u.dispatchAction(e),
		exportState: () => u.exportState(),
		importState: (e) => {
			u.importState(e);
		},
		snapshot: () => u.snapshot(),
		setLocale(e, n) {
			s = e, c = Ze(s, n ?? t.messages), K(), u.definition !== null && A !== "loading" && q(), G(A);
		},
		subscribe(e, t) {
			let n = O.get(e);
			n === void 0 && (n = /* @__PURE__ */ new Set(), O.set(e, n));
			let r = t;
			return n.add(r), () => {
				O.get(e)?.delete(r);
			};
		},
		suspend() {
			L || R || (R = !0, u.status === "running" && (u.pause(), z = !0), re(), p !== null && u.definition !== null && He(u, p, m), J && n.removeEventListener("visibilitychange", oe));
		},
		resume() {
			!L && R && (R = !1, J && n.addEventListener("visibilitychange", oe), z && u.status === "paused" && n.visibilityState !== "hidden" && (z = !1, u.start()), re());
		},
		destroy() {
			if (!L) {
				L = !0, M++, N?.abort(), B?.(), B = null, P !== null && clearInterval(P), P = null, J && n.removeEventListener("visibilitychange", oe), _.removeEventListener("click", ae), k?.destroy(), k = null;
				for (let e of ie) e();
				O.clear(), u.destroy(), r.replaceChildren(), i.removeAttribute("data-state"), a && i.remove(), ut.delete(e);
			}
		}
	};
	return ut.set(e, le), ee(), K(), G("empty"), (t.definition !== void 0 || t.src !== void 0) && Y(t.definition ?? t.src, t.state === void 0 ? {} : { state: t.state }).catch(() => void 0), le;
}
// src/element.ts
var pt = "image-puzzle-player", mt = {
	ready: "puzzle-ready",
	move: "puzzle-move",
	"state-change": "puzzle-state-change",
	progress: "puzzle-progress",
	complete: "puzzle-complete",
	error: "puzzle-error"
}, ht = [
	"definition",
	"state",
	"options",
	"src",
	"locale"
];
function gt(e = pt) {
	if (typeof customElements > "u" || typeof HTMLElement > "u") return;
	let t = customElements.get(e);
	if (t !== void 0) return t;
	class n extends HTMLElement {
		static observedAttributes = ["src", "locale"];
		#e = null;
		#t = null;
		#n = void 0;
		#r = {};
		#i = void 0;
		#a = !1;
		constructor() {
			super(), this.shadowRoot === null && this.attachShadow({ mode: "open" });
		}
		connectedCallback() {
			for (let e of ht) if (Object.prototype.hasOwnProperty.call(this, e)) {
				let t = this[e];
				Reflect.deleteProperty(this, e), this[e] = t;
			}
			this.#e === null ? this.#o() : this.#e.resume(), this.#u();
		}
		disconnectedCallback() {
			queueMicrotask(() => {
				this.isConnected || this.#e?.suspend();
			});
		}
		attributeChangedCallback(e, t, n) {
			t !== n && (e === "locale" ? this.#e?.setLocale(n ?? void 0, this.#r.messages) : this.#u());
		}
		get definition() {
			return this.#t;
		}
		set definition(e) {
			this.#t = e ?? null, this.#u();
		}
		get state() {
			return this.#n;
		}
		set state(e) {
			this.#n = e ?? void 0, this.#u(!0);
		}
		get options() {
			return this.#r;
		}
		set options(e) {
			this.#r = { ...e };
		}
		get src() {
			return this.getAttribute("src");
		}
		set src(e) {
			e === null ? this.removeAttribute("src") : this.setAttribute("src", e);
		}
		get locale() {
			return this.getAttribute("locale");
		}
		set locale(e) {
			e === null ? this.removeAttribute("locale") : this.setAttribute("locale", e);
		}
		get player() {
			return this.#o();
		}
		get engine() {
			return this.#o().engine;
		}
		load(e, t = {}) {
			let n = this.#o(), r = e ?? this.#s();
			if (r === null) return Promise.reject(new o("invalid", "No definition or src to load."));
			let i = t.state ?? this.#l();
			return this.#i = e === void 0 ? this.#c() : void 0, n.load(r, {
				...t,
				...i === void 0 ? {} : { state: i }
			});
		}
		start() {
			this.#o().start();
		}
		pause() {
			this.#o().pause();
		}
		reset() {
			this.#o().reset();
		}
		shuffle(e) {
			this.#o().shuffle(e);
		}
		undo() {
			return this.#o().undo();
		}
		redo() {
			return this.#o().redo();
		}
		hint() {
			return this.#o().hint();
		}
		dispatchAction(e) {
			return this.#o().dispatchAction(e);
		}
		exportState() {
			return this.#o().exportState();
		}
		importState(e) {
			this.#o().importState(e);
		}
		snapshot() {
			return this.#o().snapshot();
		}
		dispose() {
			this.#e?.destroy(), this.#e = null, this.#i = void 0;
		}
		#o() {
			if (this.#e === null) {
				let e = this.shadowRoot, t = this.getAttribute("locale"), n = this.getAttribute("motion"), r = ft(e, {
					...this.hasAttribute("persist") ? { persist: !0 } : {},
					...this.hasAttribute("no-controls") ? { controls: !1 } : {},
					...n === "reduce" || n === "full" ? { motion: n } : {},
					...this.#r,
					...t === null ? {} : { locale: t }
				});
				for (let [e, t] of Object.entries(mt)) r.subscribe(e, (e) => {
					this.dispatchEvent(new CustomEvent(t, {
						detail: e,
						bubbles: !0,
						composed: !0
					}));
				});
				this.#e = r;
			}
			return this.#e;
		}
		#s() {
			return this.#t ?? this.getAttribute("src");
		}
		#c() {
			let e = this.getAttribute("src");
			return this.#t ?? (e === null ? null : new URL(e, this.ownerDocument.baseURI).href);
		}
		#l() {
			let e = this.#n;
			return this.#n = void 0, e;
		}
		#u(e = !1) {
			e && (this.#i = void 0), !this.#a && (this.#a = !0, queueMicrotask(() => {
				if (this.#a = !1, !this.isConnected || this.#e === null) return;
				let e = this.#c();
				if (e === null || e === this.#i) return;
				this.#i = e;
				let t = this.#l();
				this.#e.load(this.#s(), t === void 0 ? {} : { state: t }).catch(() => void 0);
			}));
		}
	}
	return customElements.define(e, n), n;
}
// src/browser.ts
gt();
export { pt as DEFAULT_TAG, mt as ELEMENT_EVENTS, Me as HISTORY_LIMIT, c as IllegalActionError, s as InvalidDocumentError, ct as MAX_DEFINITION_BYTES, o as PuzzleError, Re as createEngine, ft as createPlayer, gt as defineImagePuzzlePlayer, Xe as dictionaries, Je as en, $ as format, Qe as formatTime, Ye as it, Te as parseDefinition, Ze as resolveMessages };

//# sourceMappingURL=image-puzzle-player.bundle.js.map