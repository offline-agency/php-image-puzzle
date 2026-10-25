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
function te(e, t) {
	let n = t.shuffle(e);
	if (n.length > 1 && n.every((t, n) => t === e[n])) {
		let e = n[0];
		n[0] = n[1], n[1] = e;
	}
	return n;
}
function W(e, t, n, r) {
	let i = f(n, "action"), a = v(i, "type", "action", 32);
	if (!t.includes(a)) throw new c(`The ${e} mode does not support "${a}" actions.`);
	return m(i, ["type", ...r], "action"), i;
}
function G(e, t) {
	return p(e[t], `board.${t}`, 4096).map((e) => y(e, `board.${t}[]`, 32));
}
function K(e, t) {
	return e.length === t.length && e.every((e, n) => e === t[n]);
}
// ../engine/dist/rules/rotation.js
var q = ["rotate"], ne = [
	0,
	90,
	180,
	270
];
function re(e) {
	let t = p(e.rules.orientations, "definition.rules.orientations", 4).map((e, t) => _(e, `definition.rules.orientations[${t}]`)), n = [...t].sort((e, t) => e - t);
	if (!(t.every((e, t) => e === n[t]) && new Set(t).size === t.length && t.every((e) => ne.includes(e)) && t.length >= 2 && t[0] === 0)) throw new s("definition.rules.orientations must be an ascending subset of [0, 90, 180, 270] with 0 and at least two angles.");
	return t;
}
function J(e) {
	return p(e.orientations, "board.orientations", 4096).map((e) => _(e, "board.orientations[]"));
}
var ie = {
	kind: "rotation",
	actionTypes: q,
	capabilities: {
		undo: !0,
		redo: !0,
		reset: !0,
		shuffle: !0,
		hint: !0
	},
	validateDefinition(e) {
		H(e, 1, 1, 16), m(e.rules, ["orientations"], "definition.rules");
		let t = re(e), [n, r] = B(e);
		if (n !== r && (t.includes(90) || t.includes(270))) throw new s(`Quarter turns need square cells; cells are ${n}x${r}.`);
	},
	solvedBoard(e) {
		return { orientations: e.tiles.map(() => 0) };
	},
	scramble(e, t) {
		let n = new E(t, "image-puzzle/scramble/rotation"), r = re(e), i = e.tiles.map(() => r[n.int(r.length)]);
		return i.some((e) => e !== 0) || (i[0] = r[1]), { orientations: i };
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		m(n, ["orientations"], "board");
		let r = p(n.orientations, "board.orientations", 4096);
		if (r.length !== e.tiles.length) throw new s("board.orientations must have one entry per tile.");
		let i = re(e);
		return { orientations: r.map((e, t) => {
			let n = _(e, `board.orientations[${t}]`);
			if (!i.includes(n)) throw new s(`board.orientations[${t}] = ${n} is not an allowed orientation.`);
			return n;
		}) };
	},
	apply(e, t, n) {
		let r = W("rotation", q, n, ["tile", "direction"]), i = x(r.tile, "action.tile"), a = v(r, "direction", "action", 8);
		if (a !== "cw" && a !== "ccw") throw new s("action.direction must be \"cw\" or \"ccw\".");
		let o = e.tiles.findIndex((e) => e.id === i);
		if (o < 0) throw new c(`Tile "${i}" is not on the board.`);
		let l = J(t), u = re(e), d = u.indexOf(l[o] ?? -1);
		if (d < 0) throw new s(`board.orientations[${o}] is not an allowed orientation.`);
		let f = u.length;
		return l[o] = u[(d + (a === "cw" ? 1 : f - 1)) % f], { orientations: l };
	},
	legalActions(e) {
		let t = re(e).length > 2, n = [];
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
		return J(t).every((e) => e === 0);
	},
	progress(e, t) {
		let n = J(t);
		return n.filter((e) => e === 0).length / Math.max(1, n.length);
	},
	hint(e, t) {
		let n = re(e), r = n.length, i = J(t);
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
}, ae = 16, oe = 1e5, se = ["slide"];
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
		H(e, 2, 2, ae), m(e.rules, ["empty", "scrambleMoves"], "definition.rules");
		let t = x(e.rules.empty, "definition.rules.empty");
		if (!e.tiles.some((e) => e.id === t)) throw new s(`definition.rules.empty names an unknown tile "${t}".`);
		g(e.rules, "scrambleMoves", "definition.rules", 1, oe);
	},
	solvedBoard(e) {
		let t = ce(e);
		return { cells: U(e).map((e) => e === t ? null : e) };
	},
	scramble(e, t) {
		let n = new E(t, "image-puzzle/scramble/sliding"), r = this.solvedBoard(e), i = JSON.stringify(r), a = g(e.rules, "scrambleMoves", "definition.rules", 1, oe), o = null, c = a + 4 * e.tiles.length;
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
		let r = x(W("sliding", se, n, ["tile"]).tile, "action.tile"), i = Y(t), a = i.indexOf(r);
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
var X = {
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
		return { order: te(U(e), new E(t, "image-puzzle/scramble/strips")) };
	},
	validateBoard(e, t) {
		let n = f(t, "board");
		return m(n, ["order"], "board"), { order: ee(n.order, U(e), "board.order") };
	},
	apply(e, t, n) {
		let r = W("strips", me, n, ["strip", "to"]), i = x(r.strip, "action.strip"), a = G(t, "order"), o = g(r, "to", "action", 0, Math.max(0, a.length - 1)), s = a.indexOf(i);
		if (s < 0) throw new c(`Strip "${i}" is not on the board.`);
		if (s === o) throw new c(`Strip "${i}" is already at position ${o}.`);
		return a.splice(s, 1), a.splice(o, 0, i), { order: a };
	},
	legalActions(e, t) {
		let n = G(t, "order"), r = [];
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
		return K(G(t, "order"), U(e));
	},
	progress(e, t) {
		let n = U(e);
		return G(t, "order").filter((e, t) => e === n[t]).length / Math.max(1, n.length);
	},
	hint(e, t) {
		let n = G(t, "order"), r = U(e);
		for (let e = 0; e < r.length; e++) if (n[e] !== r[e]) return {
			type: "move",
			strip: r[e],
			to: e
		};
		return null;
	}
}, ge = ["swap"], _e = {
	sliding: pe,
	swap: {
		kind: "swap",
		actionTypes: ge,
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
			return { cells: te(U(e), new E(t, "image-puzzle/scramble/swap")) };
		},
		validateBoard(e, t) {
			let n = f(t, "board");
			return m(n, ["cells"], "board"), { cells: ee(n.cells, U(e), "board.cells") };
		},
		apply(e, t, n) {
			let r = W("swap", ge, n, ["a", "b"]), i = x(r.a, "action.a"), a = x(r.b, "action.b");
			if (i === a) throw new c("A swap needs two different tiles.");
			let o = G(t, "cells"), s = o.indexOf(i), l = o.indexOf(a);
			if (s < 0 || l < 0) throw new c("Both tiles of a swap must be on the board.");
			return o[s] = a, o[l] = i, { cells: o };
		},
		legalActions(e, t) {
			let n = G(t, "cells"), r = [];
			for (let e = 0; e < n.length; e++) for (let t = e + 1; t < n.length; t++) r.push({
				type: "swap",
				a: n[e],
				b: n[t]
			});
			return r;
		},
		isComplete(e, t) {
			return K(G(t, "cells"), U(e));
		},
		progress(e, t) {
			let n = U(e);
			return G(t, "cells").filter((e, t) => e === n[t]).length / Math.max(1, n.length);
		},
		hint(e, t) {
			let n = G(t, "cells"), r = U(e);
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
	rotation: ie,
	strips: X,
	jigsaw: z
};
function ve(e) {
	let t = _e[e];
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
	let T = ve(i);
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
	let i = ve(e.kind), a = i.validateBoard(e, n.board), o = b(n, "completed", "state");
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
		return ve(e.definition.kind).progress(e.definition, e.state.board);
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
		let i = ve(e.definition.kind).isComplete(e.definition, t);
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
				i = ve(t.definition.kind).scramble(t.definition, r);
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
				n = ve(t.definition.kind).apply(t.definition, t.state.board, e);
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
			return e.state.completed ? [] : ve(e.definition.kind).legalActions(e.definition, e.state.board);
		},
		hint() {
			let e = f();
			if (!e.definition.capabilities.hint || e.state.completed) return null;
			let t = ve(e.definition.kind).hint(e.definition, e.state.board);
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
// ../engine/dist/build-config.js
var Ke = "image-puzzle/build-config", qe = 1e5, Je = 2147483647, Ye = [
	"sliding",
	"swap",
	"rotation",
	"strips",
	"jigsaw"
], Xe = [
	"easy",
	"medium",
	"hard"
], Ze = {
	sliding: [
		"rows",
		"cols",
		"scrambleMoves",
		"assistance"
	],
	swap: [
		"rows",
		"cols",
		"assistance"
	],
	rotation: [
		"rows",
		"cols",
		"orientations",
		"assistance"
	],
	strips: [
		"count",
		"axis",
		"assistance"
	],
	jigsaw: [
		"rows",
		"cols",
		"topology",
		"edge",
		"snap"
	]
}, Qe = {
	sliding: {
		easy: {
			rows: 3,
			cols: 3,
			scrambleMoves: 60
		},
		medium: {
			rows: 4,
			cols: 4,
			scrambleMoves: 200
		},
		hard: {
			rows: 5,
			cols: 5,
			scrambleMoves: 500
		}
	},
	swap: {
		easy: {
			rows: 3,
			cols: 3
		},
		medium: {
			rows: 4,
			cols: 4
		},
		hard: {
			rows: 6,
			cols: 6
		}
	},
	rotation: {
		easy: {
			rows: 3,
			cols: 3,
			orientations: [0, 180]
		},
		medium: {
			rows: 4,
			cols: 4,
			orientations: [
				0,
				90,
				180,
				270
			]
		},
		hard: {
			rows: 6,
			cols: 6,
			orientations: [
				0,
				90,
				180,
				270
			]
		}
	},
	strips: {
		easy: {
			count: 4,
			axis: "horizontal"
		},
		medium: {
			count: 8,
			axis: "horizontal"
		},
		hard: {
			count: 14,
			axis: "vertical"
		}
	},
	jigsaw: {
		easy: {
			rows: 3,
			cols: 4,
			edge: "jigsaw"
		},
		medium: {
			rows: 6,
			cols: 8,
			edge: "jigsaw"
		},
		hard: {
			rows: 10,
			cols: 12,
			edge: "jigsaw"
		}
	}
};
function $e(e) {
	return Ye.includes(e);
}
function et(e) {
	return Xe.includes(e);
}
function tt(e) {
	let t = f(e, "config");
	if (m(t, [
		"$schema",
		"format",
		"version",
		"kind",
		"preset",
		"custom",
		"seed",
		"crop"
	], "config"), t.format !== "image-puzzle/build-config") throw new s(`config.format must be "${Ke}".`);
	let n = g(t, "version", "config", 1);
	if (n !== 1) throw new s(`Unsupported build configuration version ${n}; this library reads version 1.`);
	let r = v(t, "kind", "config", 32);
	if (!$e(r)) throw new s(`config.kind must be one of ${Ye.join(", ")}.`);
	let i = null;
	if (h(t, "crop") && t.crop !== null) {
		let e = f(t.crop, "config.crop");
		m(e, [
			"x",
			"y",
			"width",
			"height"
		], "config.crop"), i = {
			x: g(e, "x", "config.crop", 0, qe),
			y: g(e, "y", "config.crop", 0, qe),
			width: g(e, "width", "config.crop", 1, qe),
			height: g(e, "height", "config.crop", 1, qe)
		};
	}
	return nt({
		kind: r,
		preset: v(t, "preset", "config", 32),
		custom: h(t, "custom") && t.custom !== null ? f(t.custom, "config.custom") : {},
		seed: g(t, "seed", "config", 0, C),
		crop: i
	});
}
function nt(e) {
	let t = e.custom ?? {}, n = e.seed ?? 0, r = e.preset;
	if (r !== "custom" && !et(r)) throw new s(`config.preset must be one of ${Xe.join(", ")} or "custom".`);
	let i = Object.keys(t).length > 0;
	if (r !== "custom" && i) throw new s("config.custom requires the \"custom\" preset.");
	if (r === "custom") {
		if (!i) throw new s("config.custom is required with the \"custom\" preset.");
		m(t, Ze[e.kind], "config.custom");
	}
	let a = e.kind === "jigsaw" ? Je : C;
	if (!Number.isSafeInteger(n) || n < 0 || n > a) throw new s(`config.seed must be between 0 and ${a} for ${e.kind}, got ${n}.`);
	return {
		kind: e.kind,
		preset: r,
		custom: t,
		seed: n,
		crop: e.crop ?? null
	};
}
function rt(e) {
	return {
		format: Ke,
		version: 1,
		kind: e.kind,
		preset: e.preset,
		...Object.keys(e.custom).length > 0 ? { custom: e.custom } : {},
		seed: e.seed,
		...e.crop === null ? {} : { crop: {
			x: e.crop.x,
			y: e.crop.y,
			width: e.crop.width,
			height: e.crop.height
		} }
	};
}
function it(e) {
	let t = e.preset === "custom" ? e.custom : Qe[e.kind][e.preset], n = (e, n) => {
		let r = t[e];
		return typeof r == "number" && Number.isSafeInteger(r) && r > 0 ? r : n;
	};
	if (e.kind === "strips") return {
		type: "strips",
		count: n("count", 4),
		axis: t.axis === "vertical" ? "vertical" : "horizontal"
	};
	let r = e.kind === "jigsaw" && typeof t.topology == "string" ? t.topology : "rect";
	return {
		type: "grid",
		rows: n("rows", 3),
		cols: n("cols", 3),
		topology: r
	};
}
// src/configurator-messages.ts
var at = {
	label: "Puzzle configuration",
	kind: "Game mode",
	kinds: {
		sliding: "Sliding",
		swap: "Swap",
		rotation: "Rotation",
		strips: "Strips",
		jigsaw: "Jigsaw"
	},
	preset: "Difficulty",
	presets: {
		easy: "Easy",
		medium: "Medium",
		hard: "Hard",
		custom: "Custom"
	},
	parameters: "Custom parameters",
	fields: {
		rows: "Rows",
		cols: "Columns",
		scrambleMoves: "Scramble moves",
		orientations: "Rotations",
		count: "Strips",
		axis: "Direction",
		topology: "Piece layout",
		edge: "Edge",
		snap: "Snap distance (px)"
	},
	choices: {
		horizontal: "Horizontal",
		vertical: "Vertical",
		rect: "Rectangular",
		hex: "Hexagonal",
		triangle: "Triangular",
		jigsaw: "Jigsaw tabs",
		line: "Straight",
		arc: "Arc",
		half_circle: "Half circle",
		sinus: "Wave",
		no_edge: "None"
	},
	optional: "(optional)",
	seed: "Seed",
	randomSeed: "New seed",
	crop: "Crop",
	cropX: "Left (px)",
	cropY: "Top (px)",
	cropWidth: "Width (px)",
	cropHeight: "Height (px)",
	resetCrop: "Use the whole image",
	cropArea: "Crop area {width} × {height} px at {x}, {y}",
	cropFrame: "crop frame",
	cropHelp: "Drag the frame or its corners. With the keyboard: arrows move the frame, Shift+arrows resize it.",
	noImage: "Choose an image to preview the crop and the board.",
	imageError: "The image could not be loaded for the preview.",
	preview: "Preview",
	gridSummary: "{rows} × {cols} board, tiles of about {width} × {height} px",
	stripsSummary: "{count} {axis} strips of about {size} px",
	approximate: "Piece outlines are drawn when the puzzle is generated; the preview shows the grid.",
	range: "{field} must be a whole number from {min} to {max}.",
	required: "{field} is required.",
	orientationsRequired: "Choose at least one rotation.",
	cropOutside: "The crop must lie inside the {width} × {height} px image.",
	tooSmall: "The crop is too small for this board."
}, ot = {
	label: "Configurazione del puzzle",
	kind: "Modalità di gioco",
	kinds: {
		sliding: "Scorrevole",
		swap: "Scambio",
		rotation: "Rotazione",
		strips: "Strisce",
		jigsaw: "Jigsaw"
	},
	preset: "Difficoltà",
	presets: {
		easy: "Facile",
		medium: "Media",
		hard: "Difficile",
		custom: "Personalizzata"
	},
	parameters: "Parametri personalizzati",
	fields: {
		rows: "Righe",
		cols: "Colonne",
		scrambleMoves: "Mosse di mescolamento",
		orientations: "Rotazioni",
		count: "Strisce",
		axis: "Direzione",
		topology: "Forma dei pezzi",
		edge: "Bordo",
		snap: "Distanza di aggancio (px)"
	},
	choices: {
		horizontal: "Orizzontali",
		vertical: "Verticali",
		rect: "Rettangolare",
		hex: "Esagonale",
		triangle: "Triangolare",
		jigsaw: "Incastri",
		line: "Dritto",
		arc: "Arco",
		half_circle: "Semicerchio",
		sinus: "Onda",
		no_edge: "Nessuno"
	},
	optional: "(facoltativo)",
	seed: "Seme",
	randomSeed: "Nuovo seme",
	crop: "Ritaglio",
	cropX: "Sinistra (px)",
	cropY: "Alto (px)",
	cropWidth: "Larghezza (px)",
	cropHeight: "Altezza (px)",
	resetCrop: "Usa tutta l'immagine",
	cropArea: "Area di ritaglio {width} × {height} px in {x}, {y}",
	cropFrame: "cornice di ritaglio",
	cropHelp: "Trascina la cornice o i suoi angoli. Da tastiera: le frecce spostano la cornice, Maiusc+frecce la ridimensionano.",
	noImage: "Scegli un'immagine per vedere l'anteprima del ritaglio e della griglia.",
	imageError: "Impossibile caricare l'immagine per l'anteprima.",
	preview: "Anteprima",
	gridSummary: "Griglia {rows} × {cols}, tessere di circa {width} × {height} px",
	stripsSummary: "{count} strisce {axis} di circa {size} px",
	approximate: "I contorni dei pezzi vengono disegnati durante la generazione; l'anteprima mostra la griglia.",
	range: "{field} deve essere un numero intero da {min} a {max}.",
	required: "{field} è obbligatorio.",
	orientationsRequired: "Scegli almeno una rotazione.",
	cropOutside: "Il ritaglio deve stare dentro l'immagine di {width} × {height} px.",
	tooSmall: "Il ritaglio è troppo piccolo per questa griglia."
}, st = {
	en: at,
	it: ot
};
function ct(e, t) {
	return {
		...(e === void 0 ? void 0 : st[e] ?? st[e.split("-")[0] ?? ""]) ?? at,
		...t
	};
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
function lt(e, t) {
	let n = new URL(e, t);
	if (![
		"http:",
		"https:",
		"blob:",
		"file:"
	].includes(n.protocol)) throw Error(`Unsupported asset URL scheme ${n.protocol}`);
	return n.href;
}
function ut(e) {
	return `url("${e.replace(/["\\\n\r]/g, (e) => encodeURIComponent(e))}")`;
}
// src/messages.ts
var dt = {
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
}, ft = {
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
}, pt = {
	en: dt,
	it: ft
};
function mt(e, t) {
	return {
		...(e === void 0 ? void 0 : pt[e] ?? pt[e.split("-")[0] ?? ""]) ?? dt,
		...t
	};
}
function $(e, t) {
	return e.replace(/\{(\w+)\}/g, (e, n) => {
		let r = t[n];
		return r === void 0 ? e : String(r);
	});
}
function ht(e) {
	let t = Math.floor(e / 1e3), n = Math.floor(t / 3600), r = Math.floor(t % 3600 / 60), i = t % 60, a = String(r).padStart(n > 0 ? 2 : 1, "0"), o = String(i).padStart(2, "0");
	return n > 0 ? `${n}:${a}:${o}` : `${a}:${o}`;
}
// src/theme.ts
var gt = "\n  --ipp-fg: #e6e9ee;\n  --ipp-muted: #a9b2be;\n  --ipp-bg: #15191f;\n  --ipp-surface: #232933;\n  --ipp-border: #56606d;\n  --ipp-accent: #7aa7ff;\n  --ipp-accent-fg: #0b1220;\n  --ipp-focus: #ff7ab8;\n  --ipp-correct: #5fd38a;\n  --ipp-hint: #ffb45c;\n  --ipp-error: #ff8a80;\n  --ipp-tray: #1d232b;\n  color-scheme: dark;\n", _t = `
:host {
  --ipp-font: system-ui, sans-serif;
  --ipp-fg: #1d232b;
  --ipp-muted: #55606e;
  --ipp-bg: #ffffff;
  --ipp-surface: #eef1f5;
  --ipp-border: #c4ccd6;
  --ipp-accent: #1f5fbf;
  --ipp-accent-fg: #ffffff;
  --ipp-focus: #d1006a;
  --ipp-correct: #1b7f3b;
  --ipp-hint: #b35c00;
  --ipp-error: #b00020;
  --ipp-radius: 6px;
  --ipp-gap: 2px;
  --ipp-tray: #dde3ea;
  --ipp-duration: 160ms;
  color-scheme: light;
}
:host([theme='dark']) {${gt}}
@media (prefers-color-scheme: dark) {
  :host(:not([theme='light'])) {${gt}}
}
`, vt = {
	sliding: [
		{
			key: "rows",
			type: "int",
			min: 2,
			max: 16
		},
		{
			key: "cols",
			type: "int",
			min: 2,
			max: 16
		},
		{
			key: "scrambleMoves",
			type: "int",
			min: 1,
			max: 1e5
		}
	],
	swap: [{
		key: "rows",
		type: "int",
		min: 1,
		max: 16
	}, {
		key: "cols",
		type: "int",
		min: 1,
		max: 16
	}],
	rotation: [
		{
			key: "rows",
			type: "int",
			min: 1,
			max: 16
		},
		{
			key: "cols",
			type: "int",
			min: 1,
			max: 16
		},
		{
			key: "orientations",
			type: "angles"
		}
	],
	strips: [{
		key: "count",
		type: "int",
		min: 2,
		max: 128
	}, {
		key: "axis",
		type: "choice",
		options: ["horizontal", "vertical"]
	}],
	jigsaw: [
		{
			key: "rows",
			type: "int",
			min: 1,
			max: 64
		},
		{
			key: "cols",
			type: "int",
			min: 1,
			max: 64
		},
		{
			key: "topology",
			type: "choice",
			options: [
				"rect",
				"hex",
				"triangle"
			]
		},
		{
			key: "edge",
			type: "choice",
			options: [
				"jigsaw",
				"line",
				"arc",
				"half_circle",
				"sinus",
				"no_edge"
			]
		},
		{
			key: "snap",
			type: "int",
			min: 1,
			max: 256,
			optional: !0
		}
	]
}, yt = [
	0,
	90,
	180,
	270
], bt = `${_t}
:host { display: block; color: var(--ipp-fg); font-family: var(--ipp-font); container-type: inline-size; }
:host([hidden]) { display: none; }
* { box-sizing: border-box; }
.root { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); background: var(--ipp-bg); }
@container (min-width: 44rem) { .root { grid-template-columns: minmax(15rem, 20rem) minmax(0, 1fr); } }
.fields { display: grid; gap: 0.75rem; align-content: start; }
fieldset { margin: 0; padding: 0.5rem 0.75rem 0.75rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); display: grid; gap: 0.5rem; }
fieldset[hidden] { display: none; }
legend { padding: 0 0.25rem; font-weight: 600; font-size: 0.875rem; }
label { display: grid; gap: 0.25rem; font-size: 0.875rem; }
.pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
.inline { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; }
.inline label { display: flex; align-items: center; gap: 0.375rem; }
input, select, button { font: inherit; font-size: 0.875rem; color: var(--ipp-fg); }
input[type='number'], select { min-height: 2.5rem; padding: 0.25rem 0.5rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); background: var(--ipp-bg); width: 100%; }
input[type='checkbox'] { width: 1.125rem; height: 1.125rem; accent-color: var(--ipp-accent); }
input[aria-invalid='true'], select[aria-invalid='true'] { border-color: var(--ipp-error); }
.row { display: flex; gap: 0.5rem; align-items: end; }
.row > label { flex: 1; }
button { min-height: 2.5rem; padding: 0.25rem 0.75rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); background: var(--ipp-surface); cursor: pointer; }
button:disabled, input:disabled, select:disabled { opacity: 0.6; cursor: not-allowed; }
:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }
.error { margin: 0; color: var(--ipp-error); font-size: 0.875rem; }
.error:empty { display: none; }
.help, .note, .summary { margin: 0; color: var(--ipp-muted); font-size: 0.8125rem; }
.preview { display: grid; gap: 0.5rem; align-content: start; }
.stage { position: relative; width: 100%; background: var(--ipp-tray); border-radius: var(--ipp-radius); overflow: hidden; touch-action: none; user-select: none; -webkit-user-select: none; }
.stage[hidden], .placeholder[hidden] { display: none; }
.stage img { display: block; width: 100%; height: auto; pointer-events: none; }
.stage svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.shade { fill: rgb(0 0 0 / 0.55); fill-rule: evenodd; pointer-events: none; }
.grid line { stroke: #fff; stroke-opacity: 0.85; vector-effect: non-scaling-stroke; stroke-width: 1; pointer-events: none; }
.frame { fill: transparent; stroke: #fff; stroke-width: 2; vector-effect: non-scaling-stroke; cursor: move; }
.frame:focus { outline: none; }
.frame:focus-visible { stroke: var(--ipp-focus); stroke-width: 4; }
.handle { fill: #fff; stroke: #1d232b; stroke-width: 1; vector-effect: non-scaling-stroke; }
.handle[data-corner='nw'], .handle[data-corner='se'] { cursor: nwse-resize; }
.handle[data-corner='ne'], .handle[data-corner='sw'] { cursor: nesw-resize; }
:host([data-disabled]) .frame, :host([data-disabled]) .handle { cursor: default; }
.placeholder { margin: 0; padding: 2rem 1rem; border: 1px dashed var(--ipp-border); border-radius: var(--ipp-radius); color: var(--ipp-muted); text-align: center; }
.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
@media (forced-colors: active) {
  .frame, .grid line { stroke: CanvasText; }
  .handle { fill: Canvas; stroke: CanvasText; }
  .frame:focus-visible { stroke: Highlight; }
}
`, xt = "http://www.w3.org/2000/svg", St = 0;
function Ct(e) {
	return {
		kind: e,
		preset: "medium",
		custom: {},
		seed: 1,
		crop: null
	};
}
function wt(e, t) {
	let n = Qe[e][t] ?? Qe[e].medium, r = {};
	for (let t of vt[e]) {
		let e = n[t.key];
		e !== void 0 && (r[t.key] = Array.isArray(e) ? [...e] : e);
	}
	return e === "jigsaw" && (r.topology ??= "rect"), e === "rotation" && (r.orientations ??= [
		0,
		90,
		180,
		270
	]), r;
}
function Tt(e, t = {}) {
	let n = e.ownerDocument, r = `ipc${++St}`, i, a, s = !(e instanceof ShadowRoot);
	e instanceof ShadowRoot ? (i = e, a = e.host) : (a = Z(n, "div", { class: "image-puzzle-configurator" }), e.append(a), i = a.attachShadow({ mode: "open" }));
	let c = ct(t.locale, t.messages), l = t.kinds !== void 0 && t.kinds.length > 0 ? [...t.kinds] : [...Ye], u = t.disabled ?? !1, d = t.random ?? Math.random, f = Ct(l[0] ?? "jigsaw"), p = null, m = null, h = null, g = null, _ = !1, v = null, y = !1, b = Z(n, "div", {
		class: "root",
		part: "root",
		role: "group"
	}), x = Z(n, "div", {
		class: "fields",
		part: "fields"
	}), S = Z(n, "label"), w = Z(n, "span"), T = Z(n, "select", {
		name: "kind",
		"data-field": "kind"
	});
	S.append(w, T);
	let E = Z(n, "label"), D = Z(n, "span"), O = Z(n, "select", {
		name: "preset",
		"data-field": "preset"
	});
	E.append(D, O);
	let k = Z(n, "fieldset", { "data-section": "custom" }), A = Z(n, "legend"), j = Z(n, "div", { class: "pair" });
	k.append(A, j);
	let M = Z(n, "div", { class: "row" }), N = Z(n, "label"), P = Z(n, "span"), F = Z(n, "input", {
		type: "number",
		name: "seed",
		min: "0",
		step: "1",
		inputmode: "numeric",
		"data-field": "seed"
	});
	N.append(P, F);
	let I = Z(n, "button", {
		type: "button",
		"data-action": "random-seed"
	});
	M.append(N, I);
	let L = Z(n, "fieldset", { "data-section": "crop" }), R = Z(n, "legend"), z = Z(n, "div", { class: "pair" }), B = {}, V = {};
	for (let e of [
		"x",
		"y",
		"width",
		"height"
	]) {
		let t = Z(n, "label");
		V[e] = Z(n, "span"), B[e] = Z(n, "input", {
			type: "number",
			name: `crop-${e}`,
			min: e === "x" || e === "y" ? "0" : "1",
			max: String(qe),
			step: "1",
			inputmode: "numeric",
			"data-field": `crop.${e}`
		}), t.append(V[e], B[e]), z.append(t);
	}
	let H = Z(n, "button", {
		type: "button",
		"data-action": "reset-crop"
	});
	L.append(R, z, H);
	let U = Z(n, "p", {
		class: "error",
		part: "error",
		role: "alert",
		id: `${r}-error`
	});
	x.append(S, E, k, M, L, U);
	let ee = Z(n, "div", {
		class: "preview",
		part: "preview"
	}), te = Z(n, "span", { class: "sr-only" }), W = Z(n, "p", { class: "placeholder" }), G = Z(n, "div", {
		class: "stage",
		part: "stage"
	}), K = Z(n, "img", {
		alt: "",
		draggable: "false"
	}), q = n.createElementNS(xt, "svg"), ne = n.createElementNS(xt, "path");
	ne.setAttribute("class", "shade");
	let re = n.createElementNS(xt, "g");
	re.setAttribute("class", "grid");
	let J = n.createElementNS(xt, "rect");
	J.setAttribute("class", "frame"), J.setAttribute("role", "button"), J.setAttribute("aria-describedby", `${r}-help`);
	let ie = [
		"nw",
		"ne",
		"sw",
		"se"
	].map((e) => {
		let t = n.createElementNS(xt, "rect");
		return t.setAttribute("class", "handle"), t.setAttribute("data-corner", e), t;
	});
	q.append(ne, re, J, ...ie), G.append(K, q);
	let ae = Z(n, "p", {
		class: "help",
		id: `${r}-help`
	}), oe = Z(n, "p", {
		class: "summary",
		part: "summary",
		"aria-live": "polite"
	}), se = Z(n, "p", { class: "note" });
	ee.append(te, W, G, oe, se, ae), b.append(x, ee), i.append(Z(n, "style", {}, bt), b);
	let ce = (e, t, r, i) => {
		e.replaceChildren(...t.map((e) => Z(n, "option", { value: e }, r(e)))), e.value = t.includes(i) ? i : t[0] ?? "";
	}, Y = (e) => c.fields[e] ?? e, le = (e) => {
		let t = e.value.trim();
		if (t === "") return null;
		let n = Number(t);
		return Number.isFinite(n) ? n : NaN;
	};
	function ue() {
		j.replaceChildren();
		for (let e of vt[f.kind]) {
			if (e.type === "angles") {
				let e = Z(n, "fieldset", {
					"data-field": "orientations",
					style: "grid-column: 1 / -1"
				});
				e.append(Z(n, "legend", {}, Y("orientations")));
				let t = Z(n, "div", { class: "inline" }), r = Array.isArray(f.custom.orientations) ? f.custom.orientations : [];
				for (let e of yt) {
					let i = Z(n, "label"), a = Z(n, "input", {
						type: "checkbox",
						value: String(e),
						name: "orientations"
					});
					a.checked = r.includes(e), a.disabled = u, a.addEventListener("change", () => {
						let e = yt.filter((e) => j.querySelector(`input[name='orientations'][value='${e}']`)?.checked ?? !1);
						f.custom.orientations = e, X();
					}), i.append(a, `${e}°`), t.append(i);
				}
				e.append(t), j.append(e);
				continue;
			}
			let t = Z(n, "label"), r = Z(n, "span", {}, e.type === "int" && e.optional === !0 ? `${Y(e.key)} ${c.optional}` : Y(e.key));
			if (e.type === "choice") {
				let i = Z(n, "select", {
					name: e.key,
					"data-field": `custom.${e.key}`
				});
				ce(i, e.options, (e) => c.choices[e] ?? e, typeof f.custom[e.key] == "string" ? f.custom[e.key] : e.options[0] ?? ""), f.custom[e.key] = i.value, i.disabled = u, i.addEventListener("change", () => {
					f.custom[e.key] = i.value, X();
				}), t.append(r, i);
			} else {
				let i = Z(n, "input", {
					type: "number",
					name: e.key,
					min: String(e.min),
					max: String(e.max),
					step: "1",
					inputmode: "numeric",
					"data-field": `custom.${e.key}`
				}), a = f.custom[e.key];
				i.value = typeof a == "number" ? String(a) : "", i.disabled = u, i.addEventListener("input", () => {
					let t = le(i);
					t === null && e.optional === !0 ? Reflect.deleteProperty(f.custom, e.key) : f.custom[e.key] = t, X();
				}), t.append(r, i);
			}
			j.append(t);
		}
	}
	function de() {
		b.setAttribute("aria-label", c.label), w.textContent = c.kind, D.textContent = c.preset, A.textContent = c.parameters, P.textContent = c.seed, I.textContent = c.randomSeed, R.textContent = c.crop, V.x.textContent = c.cropX, V.y.textContent = c.cropY, V.width.textContent = c.cropWidth, V.height.textContent = c.cropHeight, H.textContent = c.resetCrop, te.textContent = c.preview, ae.textContent = c.cropHelp, ce(T, l, (e) => c.kinds[e] ?? e, f.kind), ce(O, [...Xe, "custom"], (e) => c.presets[e] ?? e, f.preset), ue();
	}
	function fe() {
		T.value = f.kind, O.value = f.preset, k.hidden = f.preset !== "custom", F.max = String(f.kind === "jigsaw" ? Je : C), le(F) !== f.seed && (F.value = Number.isNaN(f.seed) ? F.value : String(f.seed));
		for (let e of [
			"x",
			"y",
			"width",
			"height"
		]) {
			let t = f.crop?.[e];
			t === void 0 ? B[e].value = "" : le(B[e]) !== t && (B[e].value = String(t));
		}
		for (let e of [
			T,
			O,
			F,
			I,
			H,
			...Object.values(B)
		]) e.disabled = u;
		for (let e of j.querySelectorAll("input, select")) e.disabled = u;
		H.disabled = u || f.crop === null, J.setAttribute("tabindex", u || g === null ? "-1" : "0"), J.setAttribute("aria-disabled", String(u));
	}
	function pe() {
		if (f.preset === "custom") for (let e of vt[f.kind]) {
			let t = f.custom[e.key];
			if (e.type === "int") {
				if (t === void 0 && e.optional === !0) continue;
				if (t == null) return {
					config: null,
					message: $(c.required, { field: Y(e.key) }),
					field: `custom.${e.key}`
				};
				if (typeof t != "number" || !Number.isSafeInteger(t) || t < e.min || t > e.max) return {
					config: null,
					message: $(c.range, {
						field: Y(e.key),
						min: e.min,
						max: e.max
					}),
					field: `custom.${e.key}`
				};
			} else if (e.type === "angles" && (!Array.isArray(t) || t.length === 0)) return {
				config: null,
				message: c.orientationsRequired,
				field: "orientations"
			};
		}
		let e = f.kind === "jigsaw" ? Je : C;
		if (!Number.isSafeInteger(f.seed) || f.seed < 0 || f.seed > e) return {
			config: null,
			message: $(c.range, {
				field: c.seed,
				min: 0,
				max: e
			}),
			field: "seed"
		};
		let t = f.crop;
		if (t !== null) {
			for (let e of [
				"x",
				"y",
				"width",
				"height"
			]) {
				let n = e === "x" || e === "y" ? 0 : 1;
				if (!Number.isSafeInteger(t[e]) || t[e] < n || t[e] > 1e5) {
					let t = {
						x: c.cropX,
						y: c.cropY,
						width: c.cropWidth,
						height: c.cropHeight
					}[e];
					return {
						config: null,
						message: $(c.range, {
							field: t,
							min: n,
							max: qe
						}),
						field: `crop.${e}`
					};
				}
			}
			if (g !== null && (t.x + t.width > g.width || t.y + t.height > g.height)) return {
				config: null,
				message: $(c.cropOutside, g),
				field: "crop"
			};
		}
		try {
			let e = nt({
				kind: f.kind,
				preset: f.preset,
				custom: f.preset === "custom" ? { ...f.custom } : {},
				seed: f.seed,
				crop: f.crop
			}), t = me(e);
			if (t !== null) {
				let n = it(e), [r, i] = n.type === "grid" ? [n.rows, n.cols] : n.axis === "vertical" ? [1, n.count] : [n.count, 1];
				if (Math.floor(t.width / i) < 1 || Math.floor(t.height / r) < 1) return {
					config: null,
					message: c.tooSmall,
					field: "crop"
				};
			}
			return {
				config: e,
				message: null,
				field: null
			};
		} catch (e) {
			return {
				config: null,
				message: e instanceof Error ? e.message : String(e),
				field: null
			};
		}
	}
	function me(e) {
		return e.crop === null ? g === null ? null : {
			x: 0,
			y: 0,
			width: g.width,
			height: g.height
		} : e.crop;
	}
	function he(e) {
		let t = v !== null && !_;
		if (G.hidden = !t, W.hidden = t && g !== null, W.textContent = _ ? c.imageError : v === null ? c.noImage : "", v !== null && g === null && !_ && (W.hidden = !0), re.replaceChildren(), se.textContent = f.kind === "jigsaw" ? c.approximate : "", g === null) {
			oe.textContent = "";
			for (let e of [
				ne,
				J,
				...ie
			]) e.setAttribute("visibility", "hidden");
			return;
		}
		let { width: r, height: i } = g;
		q.setAttribute("viewBox", `0 0 ${r} ${i}`), q.setAttribute("preserveAspectRatio", "none");
		let a = f.crop !== null && Number.isFinite(f.crop.x + f.crop.y + f.crop.width + f.crop.height) ? f.crop : {
			x: 0,
			y: 0,
			width: r,
			height: i
		};
		for (let e of [
			ne,
			J,
			...ie
		]) e.removeAttribute("visibility");
		ne.setAttribute("d", `M0 0H${r}V${i}H0Z M${a.x} ${a.y}V${a.y + a.height}H${a.x + a.width}V${a.y}Z`), J.setAttribute("x", String(a.x)), J.setAttribute("y", String(a.y)), J.setAttribute("width", String(Math.max(0, a.width))), J.setAttribute("height", String(Math.max(0, a.height))), J.setAttribute("aria-label", $(c.cropArea, {
			x: a.x,
			y: a.y,
			width: a.width,
			height: a.height
		})), J.setAttribute("aria-roledescription", c.cropFrame);
		let o = Math.max(6, Math.min(r, i) / 30), s = {
			nw: [a.x, a.y],
			ne: [a.x + a.width, a.y],
			sw: [a.x, a.y + a.height],
			se: [a.x + a.width, a.y + a.height]
		};
		for (let e of ie) {
			let [t, n] = s[e.getAttribute("data-corner")];
			e.setAttribute("x", String(t - o / 2)), e.setAttribute("y", String(n - o / 2)), e.setAttribute("width", String(o)), e.setAttribute("height", String(o));
		}
		if (e === null) {
			oe.textContent = "";
			return;
		}
		let l = it(e), u = (e, t, r, i) => {
			let a = n.createElementNS(xt, "line");
			a.setAttribute("x1", String(e)), a.setAttribute("y1", String(t)), a.setAttribute("x2", String(r)), a.setAttribute("y2", String(i)), re.append(a);
		};
		if (l.type === "strips") {
			let e = l.axis === "vertical", t = e ? a.width : a.height, n = Math.floor(t / l.count), r = Math.floor((t - n * l.count) / 2);
			for (let t = 1; t < l.count; t++) e ? u(a.x + r + t * n, a.y, a.x + r + t * n, a.y + a.height) : u(a.x, a.y + r + t * n, a.x + a.width, a.y + r + t * n);
			let i = c.choices[l.axis] ?? l.axis;
			oe.textContent = $(c.stripsSummary, {
				count: l.count,
				axis: i.toLocaleLowerCase(),
				size: n
			});
			return;
		}
		let d = Math.floor(a.width / l.cols), p = Math.floor(a.height / l.rows), m = e.kind === "rotation" ? e.preset === "custom" ? e.custom.orientations : Qe.rotation[e.preset].orientations : [];
		Array.isArray(m) && (m.includes(90) || m.includes(270)) && (d = p = Math.min(d, p));
		let h = a.x + Math.floor((a.width - d * l.cols) / 2), y = a.y + Math.floor((a.height - p * l.rows) / 2);
		for (let e = 0; e <= l.cols; e++) u(h + e * d, y, h + e * d, y + p * l.rows);
		for (let e = 0; e <= l.rows; e++) u(h, y + e * p, h + d * l.cols, y + e * p);
		oe.textContent = $(c.gridSummary, {
			rows: l.rows,
			cols: l.cols,
			width: d,
			height: p
		});
	}
	function X(e = !0) {
		if (y) return;
		let n = pe();
		m = n.message, h = n.field, p = n.config === null ? null : rt(n.config), U.textContent = m ?? "";
		for (let e of x.querySelectorAll("[data-field]")) {
			let t = e.getAttribute("data-field") ?? "";
			h !== null && (t === h || h === "crop" && t.startsWith("crop.")) ? (e.setAttribute("aria-invalid", "true"), e.setAttribute("aria-describedby", `${r}-error`)) : (e.removeAttribute("aria-invalid"), e.removeAttribute("aria-describedby"));
		}
		if (fe(), he(n.config), e) {
			let e = {
				value: p,
				valid: p !== null,
				error: m
			};
			t.onChange?.(e), a.dispatchEvent(new CustomEvent("configurator-change", {
				detail: e,
				bubbles: !0,
				composed: !0
			}));
		}
	}
	function ge(e) {
		if (e == null) {
			f = Ct(l[0] ?? "jigsaw"), f.preset === "custom" && (f.custom = wt(f.kind, "medium"));
			return;
		}
		let t = tt(e);
		if (!l.includes(t.kind)) throw new o("invalid", `config.kind "${t.kind}" is not offered by this configurator.`);
		f = {
			kind: t.kind,
			preset: t.preset,
			custom: { ...t.custom },
			seed: t.seed,
			crop: t.crop
		};
	}
	T.addEventListener("change", () => {
		let e = T.value;
		f.kind = e, f.custom = f.preset === "custom" ? wt(e, "medium") : {}, e === "jigsaw" && f.seed > 2147483647 && (f.seed %= Je + 1), ue(), X();
	}), O.addEventListener("change", () => {
		let e = f.preset;
		f.preset = O.value, f.custom = f.preset === "custom" ? wt(f.kind, e) : {}, ue(), X();
	}), F.addEventListener("input", () => {
		f.seed = le(F) ?? NaN, X();
	}), I.addEventListener("click", () => {
		Se.randomizeSeed();
	});
	for (let e of [
		"x",
		"y",
		"width",
		"height"
	]) B[e].addEventListener("input", () => {
		let t = f.crop ?? {
			x: 0,
			y: 0,
			width: g?.width ?? 1,
			height: g?.height ?? 1
		}, n = le(B[e]);
		f.crop = {
			...t,
			[e]: n ?? NaN
		}, X();
	});
	H.addEventListener("click", () => {
		Se.resetCrop();
	}), K.addEventListener("load", () => {
		K.naturalWidth > 0 && K.naturalHeight > 0 && (g = {
			width: K.naturalWidth,
			height: K.naturalHeight
		}, _ = !1), X(!1);
	}), K.addEventListener("error", () => {
		g = null, _ = v !== null, X(!1);
	});
	let _e = null, ve = {
		nw: [
			1,
			1,
			-1,
			-1
		],
		ne: [
			0,
			1,
			1,
			-1
		],
		sw: [
			1,
			0,
			-1,
			1
		],
		se: [
			0,
			0,
			1,
			1
		]
	}, ye = (e) => {
		let t = q.getBoundingClientRect(), n = g ?? {
			width: 1,
			height: 1
		};
		return {
			x: t.width > 0 ? (e.clientX - t.left) / t.width * n.width : 0,
			y: t.height > 0 ? (e.clientY - t.top) / t.height * n.height : 0
		};
	}, be = (e) => {
		let t = g ?? {
			width: e.x + e.width,
			height: e.y + e.height
		}, n = Math.max(1, Math.min(Math.round(e.width), t.width)), r = Math.max(1, Math.min(Math.round(e.height), t.height));
		return {
			x: Math.max(0, Math.min(Math.round(e.x), t.width - n)),
			y: Math.max(0, Math.min(Math.round(e.y), t.height - r)),
			width: n,
			height: r
		};
	};
	q.addEventListener("pointerdown", (e) => {
		if (u || g === null || e.button !== 0) return;
		let t = e.target, n = t.getAttribute("data-corner");
		if (t !== J && n === null) return;
		e.preventDefault();
		let r = f.crop ?? {
			x: 0,
			y: 0,
			width: g.width,
			height: g.height
		}, i = ye(e);
		_e = {
			pointer: e.pointerId,
			mode: n ?? "move",
			start: r,
			x: i.x,
			y: i.y
		};
		try {
			q.setPointerCapture(e.pointerId);
		} catch {}
		J.focus({ preventScroll: !0 });
	}), q.addEventListener("pointermove", (e) => {
		if (_e === null || e.pointerId !== _e.pointer) return;
		let t = ye(e), n = t.x - _e.x, r = t.y - _e.y, i = _e.start;
		if (_e.mode === "move") f.crop = be({
			...i,
			x: i.x + n,
			y: i.y + r
		});
		else {
			let [e, t, a, o] = ve[_e.mode], s = g ?? {
				width: Infinity,
				height: Infinity
			}, c = i.x + e * n, l = i.y + t * r, u = i.width + a * n, d = i.height + o * r;
			c < 0 && (u += c, c = 0), l < 0 && (d += l, l = 0), u = Math.min(Math.max(1, u), s.width - c), d = Math.min(Math.max(1, d), s.height - l), f.crop = be({
				x: e === 1 ? Math.min(c, i.x + i.width - 1) : c,
				y: t === 1 ? Math.min(l, i.y + i.height - 1) : l,
				width: u,
				height: d
			});
		}
		X();
	});
	let xe = (e) => {
		_e !== null && e.pointerId === _e.pointer && (_e = null, q.hasPointerCapture(e.pointerId) && q.releasePointerCapture(e.pointerId));
	};
	q.addEventListener("pointerup", xe), q.addEventListener("pointercancel", xe), J.addEventListener("keydown", (e) => {
		if (u || g === null) return;
		let t = {
			ArrowLeft: [-1, 0],
			ArrowRight: [1, 0],
			ArrowUp: [0, -1],
			ArrowDown: [0, 1]
		}[e.key];
		if (t === void 0) return;
		e.preventDefault();
		let n = Math.max(1, Math.round(Math.min(g.width, g.height) / 50)), r = f.crop ?? {
			x: 0,
			y: 0,
			width: g.width,
			height: g.height
		};
		f.crop = e.shiftKey ? be({
			...r,
			width: r.width + t[0] * n,
			height: r.height + t[1] * n
		}) : be({
			...r,
			x: r.x + t[0] * n,
			y: r.y + t[1] * n
		}), X();
	});
	let Se = {
		element: a,
		get value() {
			return p;
		},
		get valid() {
			return p !== null;
		},
		get error() {
			return m;
		},
		get imageSize() {
			return g;
		},
		setValue(e) {
			try {
				ge(e), de(), X(!1);
			} catch (e) {
				f = Ct(l[0] ?? "jigsaw"), de(), X(!1), m = e instanceof Error ? e.message : String(e), U.textContent = m, p = null;
			}
		},
		setSource(e) {
			v = e === "" ? null : e, g = null, _ = !1, v === null ? K.removeAttribute("src") : K.src = v, X(!1);
		},
		setKinds(e) {
			l = e.length > 0 ? [...e] : [...Ye];
			let t = !l.includes(f.kind);
			t && (f.kind = l[0] ?? "jigsaw", f.custom = f.preset === "custom" ? wt(f.kind, "medium") : {}), de(), X(t);
		},
		setDisabled(e) {
			u = e, e ? a.setAttribute("data-disabled", "") : a.removeAttribute("data-disabled"), X(!1);
		},
		setLocale(e, n) {
			c = ct(e, n ?? t.messages), de(), X(!1);
		},
		randomizeSeed() {
			if (u) return;
			let e = f.kind === "jigsaw" ? Je : C;
			f.seed = Math.floor(d() * (e + 1)) % (e + 1), X();
		},
		resetCrop() {
			u || (f.crop = null, X());
		},
		destroy() {
			y || (y = !0, i.replaceChildren(), s && a.remove());
		}
	};
	return t.disabled === !0 && a.setAttribute("data-disabled", ""), Se.setValue(t.value ?? null), t.src !== void 0 && t.src !== null && Se.setSource(t.src), Se;
}
// src/configurator-element.ts
var Et = "image-puzzle-configurator", Dt = "configurator-change";
function Ot(e) {
	return e === null ? [] : e.split(/[\s,]+/).filter((e) => Ye.includes(e));
}
function kt(e = Et) {
	if (typeof customElements > "u" || typeof HTMLElement > "u") return;
	let t = customElements.get(e);
	if (t !== void 0) return t;
	class n extends HTMLElement {
		static formAssociated = !0;
		static observedAttributes = [
			"src",
			"value",
			"kinds",
			"disabled",
			"locale"
		];
		#e;
		#t;
		#n = null;
		constructor() {
			super();
			let e = this.shadowRoot ?? this.attachShadow({ mode: "open" });
			this.#t = typeof this.attachInternals == "function" ? this.attachInternals() : null, this.#e = Tt(e, { onChange: (e) => {
				this.#i(e);
			} }), this.#i({
				value: this.#e.value,
				valid: this.#e.valid,
				error: this.#e.error
			});
		}
		connectedCallback() {
			for (let e of [
				"value",
				"src",
				"kinds",
				"disabled",
				"locale",
				"name"
			]) if (Object.prototype.hasOwnProperty.call(this, e)) {
				let t = this[e];
				Reflect.deleteProperty(this, e), this[e] = t;
			}
		}
		attributeChangedCallback(e, t, n) {
			if (t !== n) {
				switch (e) {
					case "src":
						this.#e.setSource(n);
						break;
					case "value":
						this.#n = n, this.#r(n);
						break;
					case "kinds":
						this.#e.setKinds(Ot(n));
						break;
					case "disabled":
						this.#e.setDisabled(n !== null);
						break;
					case "locale": this.#e.setLocale(n ?? void 0);
				}
				this.#i({
					value: this.#e.value,
					valid: this.#e.valid,
					error: this.#e.error
				});
			}
		}
		get value() {
			return this.#e.value;
		}
		set value(e) {
			this.#r(e), this.#i({
				value: this.#e.value,
				valid: this.#e.valid,
				error: this.#e.error
			});
		}
		get src() {
			return this.getAttribute("src");
		}
		set src(e) {
			e === null ? this.removeAttribute("src") : this.setAttribute("src", e);
		}
		get kinds() {
			let e = Ot(this.getAttribute("kinds"));
			return e.length > 0 ? e : [...Ye];
		}
		set kinds(e) {
			this.setAttribute("kinds", e.join(" "));
		}
		get disabled() {
			return this.hasAttribute("disabled");
		}
		set disabled(e) {
			this.toggleAttribute("disabled", e);
		}
		get locale() {
			return this.getAttribute("locale");
		}
		set locale(e) {
			e === null ? this.removeAttribute("locale") : this.setAttribute("locale", e);
		}
		get name() {
			return this.getAttribute("name");
		}
		set name(e) {
			e === null ? this.removeAttribute("name") : this.setAttribute("name", e);
		}
		get valid() {
			return this.#e.valid;
		}
		get error() {
			return this.#e.error;
		}
		get configurator() {
			return this.#e;
		}
		randomizeSeed() {
			this.#e.randomizeSeed();
		}
		resetCrop() {
			this.#e.resetCrop();
		}
		formResetCallback() {
			this.#r(this.#n), this.#i({
				value: this.#e.value,
				valid: this.#e.valid,
				error: this.#e.error
			});
		}
		formDisabledCallback(e) {
			this.#e.setDisabled(e || this.hasAttribute("disabled"));
		}
		#r(e) {
			if (typeof e == "string") {
				let t;
				try {
					t = e.trim() === "" ? null : JSON.parse(e);
				} catch {
					t = e;
				}
				this.#e.setValue(t);
			} else this.#e.setValue(e ?? null);
		}
		#i(e) {
			let t = this.#t;
			t !== null && typeof t.setFormValue == "function" && (t.setFormValue(e.value === null ? null : JSON.stringify(e.value)), e.valid ? t.setValidity({}) : t.setValidity({ customError: !0 }, e.error ?? "Invalid configuration."));
		}
	}
	return customElements.define(e, n), n;
}
// src/styles.ts
var At = `${_t}
:host {
  display: block;
  color: var(--ipp-fg);
  font-family: var(--ipp-font);
  container-type: inline-size;
}
:host([hidden]) { display: none; }
* { box-sizing: border-box; }
.root { display: grid; gap: 0.5rem; background: var(--ipp-bg); }
.toolbar { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.toolbar[hidden] { display: none; }
button.control {
  font: inherit;
  font-size: 0.875rem;
  min-height: 2.75rem;
  min-width: 2.75rem;
  padding: 0.25rem 0.75rem;
  border: 1px solid var(--ipp-border);
  border-radius: var(--ipp-radius);
  background: var(--ipp-surface);
  color: var(--ipp-fg);
  cursor: pointer;
}
button.control[aria-pressed='true'] { background: var(--ipp-accent); color: var(--ipp-accent-fg); border-color: var(--ipp-accent); }
button.control:disabled { opacity: 0.5; cursor: not-allowed; }
button:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }
.status { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; color: var(--ipp-muted); font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.message { margin: 0; padding: 1rem; border: 1px dashed var(--ipp-border); border-radius: var(--ipp-radius); }
.message[data-kind='error'] { border-style: solid; border-color: var(--ipp-error); color: var(--ipp-error); }
.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

.board { position: relative; width: 100%; direction: ltr; background: var(--ipp-surface); border-radius: var(--ipp-radius); overflow: hidden; touch-action: manipulation; user-select: none; -webkit-user-select: none; }
.board[data-loading='true'] .tile, .board[data-loading='true'] .piece { visibility: hidden; }
.tile {
  position: absolute;
  padding: 0;
  margin: 0;
  border: 0;
  border-radius: 0;
  background-color: var(--ipp-surface);
  background-repeat: no-repeat;
  box-shadow: inset 0 0 0 calc(var(--ipp-gap) / 2) var(--ipp-bg);
  cursor: pointer;
  transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease, transform var(--ipp-duration) ease;
  touch-action: none;
}
.tile:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: -3px; z-index: 3; }
.tile[aria-pressed='true'] { box-shadow: inset 0 0 0 4px var(--ipp-accent); z-index: 2; }
.tile.is-correct::after { content: ''; position: absolute; inset: auto 4px 4px auto; width: 10px; height: 10px; border-radius: 50%; background: var(--ipp-correct); box-shadow: 0 0 0 2px var(--ipp-bg); }
.tile.is-hint, .piece.is-hint .outline { animation: ipp-hint 1s ease 2; }
.tile.is-hint { box-shadow: inset 0 0 0 4px var(--ipp-hint); }
.tile.is-dragging { transition: none; z-index: 4; opacity: 0.9; }
.tile.is-target { box-shadow: inset 0 0 0 4px var(--ipp-accent); }
.number { position: absolute; inset: 4px auto auto 4px; padding: 0 0.3em; border-radius: 3px; background: rgb(0 0 0 / 0.6); color: #fff; font-size: 0.75rem; line-height: 1.4; pointer-events: none; }
.marker { position: absolute; background: var(--ipp-accent); pointer-events: none; z-index: 5; }
.preview { position: absolute; background-repeat: no-repeat; pointer-events: none; z-index: 6; opacity: 0.85; }
.preview[hidden] { display: none; }

.viewport { position: relative; width: 100%; overflow: auto; border-radius: var(--ipp-radius); background: var(--ipp-tray); overscroll-behavior: contain; }
.stage { position: relative; background: var(--ipp-tray); }
.stage .board-area { position: absolute; background: var(--ipp-surface); outline: 1px solid var(--ipp-border); }
.stage .guides { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.stage .guides path { fill: none; stroke: var(--ipp-border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.piece { position: absolute; padding: 0; margin: 0; border: 0; background: none; pointer-events: none; transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease; }
.piece:focus-visible { outline: none; }
.piece img { display: block; width: 100%; height: 100%; pointer-events: none; -webkit-user-drag: none; }
.piece svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.piece .outline { fill: transparent; stroke: none; pointer-events: all; cursor: grab; touch-action: none; vector-effect: non-scaling-stroke; }
.piece:focus-visible .outline { stroke: var(--ipp-focus); stroke-width: 3; }
.piece.is-dragging { transition: none; }
.piece.is-dragging .outline { cursor: grabbing; }
.piece.is-placed .outline { pointer-events: none; cursor: default; }
.piece.is-placed.highlight .outline { stroke: var(--ipp-correct); stroke-width: 1; }
.piece.is-hint .outline { stroke: var(--ipp-hint); stroke-width: 3; }

@keyframes ipp-hint { 50% { opacity: 0.5; } }
@media (prefers-reduced-motion: reduce) {
  :host(:not([data-motion='full'])) .tile, :host(:not([data-motion='full'])) .piece { transition: none; }
  :host(:not([data-motion='full'])) .is-hint, :host(:not([data-motion='full'])) .is-hint .outline { animation: none; }
}
:host([data-motion='reduce']) .tile, :host([data-motion='reduce']) .piece { transition: none; }
:host([data-motion='reduce']) .is-hint, :host([data-motion='reduce']) .is-hint .outline { animation: none; }
@media (forced-colors: active) {
  .tile:focus-visible, button:focus-visible { outline-color: Highlight; }
  .piece:focus-visible .outline { stroke: Highlight; }
}
`;
// src/views/media.ts
function jt(e, t, n) {
	let r = (e, t, n) => n === t ? "0%" : Q(e / (n - t) * 100);
	return {
		backgroundImage: ut(e),
		backgroundSize: `${Q(n.width / t.width * 100)} ${Q(n.height / t.height * 100)}`,
		backgroundPosition: `${r(t.x, t.width, n.width)} ${r(t.y, t.height, n.height)}`
	};
}
function Mt(e, t, n) {
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
var Nt = 6;
function Pt(e, t, n) {
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
function Ft(e) {
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
		Object.assign(r.style, jt(l, e.rect, s), {
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
	Object.assign(_.style, jt(l, o, s), { inset: "0" }), p.append(g, _);
	let v = Mt(l, () => {
		p.setAttribute("data-loading", "false");
	}, () => {
		e.assetError(l);
	}), y = Pt(r, n.tiles, e.engine.exportState().board), b = n.tiles[0]?.id ?? "", x = null, S = null, C = /* @__PURE__ */ new Map(), w = null, T = null, E = !1, D = (e) => y.cells.indexOf(e), O = (e) => [Math.floor(e / a), e % a], k = (e, t) => r === "rotation" ? (y.orientation.get(e.id) ?? 0) === 0 : t === e.home;
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
			if (Math.hypot(t, n) < Nt) return;
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
	let te = (e) => {
		U(e, !0);
	}, W = (e) => {
		U(e, !1);
	};
	return p.addEventListener("keydown", L), p.addEventListener("click", ee), p.addEventListener("pointerdown", V), p.addEventListener("pointermove", H), p.addEventListener("pointerup", te), p.addEventListener("pointercancel", W), j(), {
		element: d,
		update(e, t) {
			y = Pt(r, n.tiles, e), t && N(), j();
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
			w !== null && clearTimeout(w), v.cancel(), p.removeEventListener("keydown", L), p.removeEventListener("click", ee), p.removeEventListener("pointerdown", V), p.removeEventListener("pointermove", H), p.removeEventListener("pointerup", te), p.removeEventListener("pointercancel", W), d.remove();
		}
	};
}
// src/views/jigsaw.ts
var It = "http://www.w3.org/2000/svg", Lt = 4;
function Rt(e) {
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
	let m = t.createElementNS(It, "svg");
	m.setAttribute("class", "guides"), m.setAttribute("viewBox", `${r.x} ${r.y} ${r.width} ${r.height}`), m.setAttribute("preserveAspectRatio", "none"), m.setAttribute("aria-hidden", "true");
	let h = Z(t, "div", {
		class: "preview",
		part: "preview",
		hidden: ""
	});
	Object.assign(h.style, jt(e.asset(n.media.image), i, n.source), f(i), { opacity: "0.4" }), d.append(p, m, h);
	let g = 0, _ = !1, v = n.tiles.map((n) => {
		let i = N(n), a = (typeof n.attributes.path == "string" ? n.attributes.path : null) ?? `M${i.x} ${i.y} L${i.x + i.width} ${i.y} L${i.x + i.width} ${i.y + i.height} L${i.x} ${i.y + i.height} Z`, o = t.createElementNS(It, "path");
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
		let u = t.createElementNS(It, "svg");
		u.setAttribute("viewBox", `${i.x} ${i.y} ${i.width} ${i.height}`), u.setAttribute("preserveAspectRatio", "none"), u.setAttribute("aria-hidden", "true");
		let f = t.createElementNS(It, "path");
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
		if (!E.active && Math.hypot(n, r) < Lt) return;
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
var zt = 4194304, Bt = [
	1,
	1.5,
	2,
	3,
	4
], Vt = /* @__PURE__ */ new WeakMap();
function Ht(e) {
	return e.baseURI;
}
function Ut(e, t = {}) {
	if (Vt.has(e)) throw new o("invalid", "This element already hosts an image puzzle player; destroy it first.");
	let n = e.ownerDocument, r, i, a = !(e instanceof ShadowRoot);
	e instanceof ShadowRoot ? (r = e, i = e.host) : (i = Z(n, "div", { class: "image-puzzle-player" }), e.append(i), r = i.attachShadow({ mode: "open" }));
	let s = t.locale, c = mt(s, t.messages), l = t.onListenerError ?? ((e) => {
		queueMicrotask(() => {
			throw e;
		});
	}), u = Re({
		...t.clock ? { clock: t.clock } : {},
		...t.randomSeed ? { randomSeed: t.randomSeed } : {},
		onListenerError: l
	}), d = t.fetch ?? ((e, t) => fetch(e, t)), f = t.persist === void 0 || t.persist === !1 ? null : t.persist === !0 ? {} : t.persist, p = f === null ? null : f.storage === void 0 ? Be() : f.storage, m = f?.prefix, h = Z(n, "style", {}, At), g = Z(n, "div", {
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
	let O = /* @__PURE__ */ new Map(), k = null, A = "empty", j = t.baseUrl ?? Ht(n), M = 0, N = null, P = null, F = !1, I = 0, L = !1, R = !1, z = !1, B = p === null ? null : We(u, p, V());
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
	function te(e, t = "") {
		T.hidden = e === null, T.dataset.kind = e ?? "", T.textContent = t;
	}
	function W(e, t = "") {
		A = e, i.setAttribute("data-state", e), g.setAttribute("aria-busy", String(e === "loading"));
		let n = c;
		e === "empty" ? te("empty", n.empty) : e === "loading" ? te("loading", n.loading) : e === "error" ? te("error", $(n.loadError, { message: t })) : te(null), E.hidden = e === "loading" || k === null, K();
	}
	function G() {
		let e = c;
		s === void 0 ? g.removeAttribute("lang") : g.lang = s, _.setAttribute("aria-label", e.controls), y.undo.textContent = e.undo, y.redo.textContent = e.redo, y.reset.textContent = e.reset, y.shuffle.textContent = e.shuffle, y.hint.textContent = e.hint, y.preview.textContent = e.preview, y.zoomOut.textContent = e.zoomOut, y.zoomIn.textContent = e.zoomIn;
	}
	function K() {
		let e = u.status === "destroyed" ? null : u.definition, t = e !== null && A !== "loading", n = u.status, r = e?.capabilities;
		y.start.hidden = !t, y.start.textContent = n === "running" ? c.pause : n === "paused" ? c.resume : c.start, y.start.disabled = n === "complete", y.undo.hidden = !t || r?.undo !== !0, y.undo.disabled = !t || !u.canUndo(), y.redo.hidden = !t || r?.redo !== !0, y.redo.disabled = !t || !u.canRedo(), y.reset.hidden = !t || r?.reset !== !0, y.shuffle.hidden = !t || r?.shuffle !== !0, y.hint.hidden = !t || r?.hint !== !0, y.hint.disabled = n === "complete", y.preview.hidden = !t || !e.assistance.preview, y.preview.setAttribute("aria-pressed", String(F));
		let i = t && e.kind === "jigsaw";
		y.zoomOut.hidden = !i, y.zoomIn.hidden = !i, y.zoomOut.disabled = I === 0, y.zoomIn.disabled = I === Bt.length - 1, b.hidden = !t, q();
	}
	function q() {
		if (u.status === "destroyed" || u.definition === null) return;
		let e = u.snapshot();
		x.textContent = $(c.moves, { n: e.moves }), S.textContent = $(c.time, { time: ht(e.elapsedMs) }), C.textContent = $(c.progress, { percent: Math.floor(e.progress * 100) }), w.hidden = !u.definition.capabilities.hint, w.textContent = $(c.hints, { n: e.hintsUsed });
	}
	function ne() {
		let e = u.status === "running" && !R;
		e && P === null ? P = setInterval(q, 1e3) : !e && P !== null && (clearInterval(P), P = null);
	}
	function re() {
		k?.destroy(), k = null, F = !1, I = 0;
		let e = u.definition;
		if (e === null) return;
		let t = {
			doc: n,
			definition: e,
			engine: u,
			messages: () => c,
			asset: (e) => lt(e, j),
			announce: U,
			dispatch: (e) => u.dispatchAction(e).ok,
			assetError: (e) => {
				H("error", {
					code: "load",
					message: `Could not load ${e}`
				}), W("error", `Could not load ${e}`);
			}
		};
		k = e.kind === "jigsaw" ? Rt(t) : Ft(t), E.replaceChildren(k.element);
	}
	let J = [
		u.subscribe("state-change", (e) => {
			e.reason === "load" ? re() : k?.update(e.state.board, e.reason !== "action"), K(), ne(), H("state-change", e);
		}),
		u.subscribe("complete", (e) => {
			U($(c.complete, {
				moves: e.moves,
				time: ht(e.elapsedMs)
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
	function ie(e) {
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
				F = !F, k?.setPreview(F), K();
				break;
			case "zoom-in":
			case "zoom-out": I = Math.min(Bt.length - 1, Math.max(0, I + (t.dataset.control === "zoom-in" ? 1 : -1))), k?.setZoom?.(Bt[I] ?? 1), K();
		}
	}
	_.addEventListener("click", ie);
	function ae() {
		n.visibilityState === "hidden" && u.status === "running" ? (u.pause(), z = !0) : n.visibilityState === "visible" && z && (z = !1, u.status === "paused" && u.start());
	}
	let oe = t.pauseWhenHidden !== !1;
	oe && n.addEventListener("visibilitychange", ae);
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
		if (Number(n.headers.get("content-length") ?? "0") > 4194304) throw new o("load", `${e} exceeds ${zt} bytes`);
		let r = await n.text();
		if (r.length > 4194304) throw new o("load", `${e} exceeds ${zt} bytes`);
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
		N = a, W("loading");
		let s, c;
		try {
			if (typeof e == "string") {
				let t = new URL(e, Ht(n)).href;
				({data: s, base: c} = await se(t, a.signal));
			} else s = e, c = r.baseUrl ?? t.baseUrl ?? Ht(n);
		} catch (e) {
			if (ce(i)) return;
			let t = e instanceof o ? e : new o("load", String(e), { cause: e });
			throw H("error", {
				code: t.code,
				message: t.message
			}), W("error", t.message), t;
		}
		if (ce(i)) return;
		N = null;
		let l = j;
		j = c;
		try {
			u.load(s, r.state);
		} catch (e) {
			throw j = l, W("error", e instanceof Error ? e.message : String(e)), e;
		}
		if (W("ready"), r.state === void 0 && p !== null) {
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
			return e === null ? U(c.noHint) : k?.showHint(e), q(), e;
		},
		dispatchAction: (e) => u.dispatchAction(e),
		exportState: () => u.exportState(),
		importState: (e) => {
			u.importState(e);
		},
		snapshot: () => u.snapshot(),
		setLocale(e, n) {
			s = e, c = mt(s, n ?? t.messages), G(), u.definition !== null && A !== "loading" && re(), W(A);
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
			L || R || (R = !0, u.status === "running" && (u.pause(), z = !0), ne(), p !== null && u.definition !== null && He(u, p, m), oe && n.removeEventListener("visibilitychange", ae));
		},
		resume() {
			!L && R && (R = !1, oe && n.addEventListener("visibilitychange", ae), z && u.status === "paused" && n.visibilityState !== "hidden" && (z = !1, u.start()), ne());
		},
		destroy() {
			if (!L) {
				L = !0, M++, N?.abort(), B?.(), B = null, P !== null && clearInterval(P), P = null, oe && n.removeEventListener("visibilitychange", ae), _.removeEventListener("click", ie), k?.destroy(), k = null;
				for (let e of J) e();
				O.clear(), u.destroy(), r.replaceChildren(), i.removeAttribute("data-state"), a && i.remove(), Vt.delete(e);
			}
		}
	};
	return Vt.set(e, le), ee(), G(), W("empty"), (t.definition !== void 0 || t.src !== void 0) && Y(t.definition ?? t.src, t.state === void 0 ? {} : { state: t.state }).catch(() => void 0), le;
}
// src/element.ts
var Wt = "image-puzzle-player", Gt = {
	ready: "puzzle-ready",
	move: "puzzle-move",
	"state-change": "puzzle-state-change",
	progress: "puzzle-progress",
	complete: "puzzle-complete",
	error: "puzzle-error"
}, Kt = [
	"definition",
	"state",
	"options",
	"src",
	"locale"
];
function qt(e = Wt) {
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
			for (let e of Kt) if (Object.prototype.hasOwnProperty.call(this, e)) {
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
				let e = this.shadowRoot, t = this.getAttribute("locale"), n = this.getAttribute("motion"), r = Ut(e, {
					...this.hasAttribute("persist") ? { persist: !0 } : {},
					...this.hasAttribute("no-controls") ? { controls: !1 } : {},
					...n === "reduce" || n === "full" ? { motion: n } : {},
					...this.#r,
					...t === null ? {} : { locale: t }
				});
				for (let [e, t] of Object.entries(Gt)) r.subscribe(e, (e) => {
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
qt(), kt();
export { Dt as CONFIGURATOR_CHANGE_EVENT, Et as CONFIGURATOR_TAG, Wt as DEFAULT_TAG, Gt as ELEMENT_EVENTS, Me as HISTORY_LIMIT, c as IllegalActionError, s as InvalidDocumentError, zt as MAX_DEFINITION_BYTES, o as PuzzleError, rt as buildConfigDocument, at as configuratorEn, ot as configuratorIt, Tt as createConfigurator, Re as createEngine, Ut as createPlayer, kt as defineImagePuzzleConfigurator, qt as defineImagePuzzlePlayer, pt as dictionaries, dt as en, $ as format, ht as formatTime, ft as it, tt as parseBuildConfig, Te as parseDefinition, ct as resolveConfiguratorMessages, mt as resolveMessages, _t as themeTokens };

//# sourceMappingURL=image-puzzle-player.bundle.js.map