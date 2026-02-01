# Split strategy

You can customize how the image is divided into rows and columns by passing a `DimensionSplitStrategy` to `PuzzleGenerator`. The default behavior (when no strategy is passed) is: **remainder pixels are assigned to the last row and last column** (e.g. 100×3 columns → 33, 33, 34).

## Interface

```php
namespace ImagePuzzle\SplitStrategy;

interface DimensionSplitStrategy
{
    /**
     * @return list<int> Segment sizes; must have exactly $segmentCount elements and sum to $totalSize
     */
    public function split(int $totalSize, int $segmentCount): array;
}
```

- `$totalSize` – total pixels in that dimension (width or height).
- `$segmentCount` – number of segments (columns or rows).
- Return an array of segment sizes that sum to `$totalSize` and has length `$segmentCount`. The generator validates this and throws `InvalidArgumentException` if not.

## Default implementation

`DefaultSplitStrategy` implements the built-in rule: base = floor(total/count), remainder to the last segment.

```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\SplitStrategy\DefaultSplitStrategy;

$generator = new PuzzleGenerator(null, null, null, new DefaultSplitStrategy());
$puzzle = $generator->generateFromFile('photo.jpg', 4, 6);
```

## Custom strategy

Implement the interface and pass it as the fourth constructor argument:

```php
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

$evenStrategy = new class implements DimensionSplitStrategy {
    public function split(int $totalSize, int $segmentCount): array
    {
        $base = (int) floor($totalSize / $segmentCount);
        $remainder = $totalSize - $base * $segmentCount;
        $splits = array_fill(0, $segmentCount, $base);
        for ($i = 0; $i < $remainder; $i++) {
            $splits[$i]++;
        }
        return $splits;
    }
};

$generator = new PuzzleGenerator(null, null, null, $evenStrategy);
```

The same strategy is used for both width (columns) and height (rows); the generator calls `split(totalWidth, cols)` and `split(totalHeight, rows)`.
