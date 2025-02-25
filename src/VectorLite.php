<?php

namespace ThaKladd\VectorLite;

use KMeans\Space;

class VectorLite
{
    /**
     * Normalize a vector.
     */
    public static function normalize(array $vector): array
    {
        $n = count($vector);
        $sum = 0.0;

        // Compute the sum of squares.
        for ($i = 0; $i < $n; $i++) {
            $val = $vector[$i];
            $sum += $val * $val;
        }

        // Compute the norm.
        $norm = sqrt($sum);

        // Avoid division by zero.
        if ($norm == 0.0) {
            $norm = 1.0;
        }

        // Pre-calculate reciprocal to avoid repeated division.
        $inv = 1.0 / $norm;

        // Normalize each element.
        for ($i = 0; $i < $n; $i++) {
            $vector[$i] *= $inv;
        }

        return $vector;
    }

    /**
     * Normalize a vector to binary.
     */
    public static function normalizeToBinary(array $vector): string
    {
        return pack('f*', ...self::normalize($vector));
    }

    public function kMeansPackage(array $vectors, $clusters = 100): array
    {
        $dimensions = count($vectors[0]);
        $space = new Space($dimensions);

        foreach ($vectors as $i => $vector) {
            $space->addPoint($vector);
        }

        return $space->solve($clusters);
    }

    public static function kMeans($vectors, $k = 100): array
    {
        $n = count($vectors);
        $d = count($vectors[0]);

        // Initialize means with the first $k vectors.
        $means = [];
        for ($i = 0; $i < $k; $i++) {
            $means[$i] = $vectors[$i];
        }

        // Pre-fill assignments with a default value (-1)
        $assignments = array_fill(0, $n, -1);
        $clusters = [];
        $changed = true;

        while ($changed) {
            $changed = false;

            // Clear clusters.
            for ($i = 0; $i < $k; $i++) {
                $clusters[$i] = [];
            }

            // Assignment step: assign each vector to the nearest mean.
            for ($i = 0; $i < $n; $i++) {
                $vector = $vectors[$i];
                $minDistance = INF;
                $minCluster = -1;

                // Compare distance to each cluster mean.
                for ($j = 0; $j < $k; $j++) {
                    $mean = $means[$j];
                    $distance = 0.0;

                    // Compute Euclidean distance (squared)
                    for ($l = 0; $l < $d; $l++) {
                        $diff = $vector[$l] - $mean[$l];
                        $distance += $diff * $diff;
                    }

                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $minCluster = $j;
                    }
                }

                // Check if the assignment has changed.
                if ($assignments[$i] !== $minCluster) {
                    $changed = true;
                    $assignments[$i] = $minCluster;
                }

                // Add vector to the appropriate cluster.
                $clusters[$minCluster][] = $vector;
            }

            // Update step: recalculate the means of each cluster.
            for ($j = 0; $j < $k; $j++) {
                $cluster = $clusters[$j];
                $m = count($cluster);
                if ($m > 0) {
                    // Initialize new mean vector.
                    $newMean = array_fill(0, $d, 0.0);

                    // Sum all vectors in the cluster.
                    for ($i = 0; $i < $m; $i++) {
                        $vector = $cluster[$i];
                        for ($l = 0; $l < $d; $l++) {
                            $newMean[$l] += $vector[$l];
                        }
                    }

                    // Divide by number of vectors to get the average.
                    $inv = 1.0 / $m;
                    for ($l = 0; $l < $d; $l++) {
                        $newMean[$l] *= $inv;
                    }
                    $means[$j] = $newMean;
                }
            }
        }

        return $means;
    }

    public static function updateClusters(
        array $clusters,
        array $newVector,
        float $similarityThreshold = 0.8,
        int $minCount = 5,
        int $maxCount = 100
    ): array {
        // First, try to assign the new vector to an existing cluster.
        $assignedClusterIndex = null;
        $maxSimilarity = -INF;
        $dim = count($newVector);

        // Compute cosine similarity (dot product) for each cluster mean.
        foreach ($clusters as $i => $cluster) {
            $similarity = 0.0;
            $mean = $cluster['mean'];
            for ($j = 0; $j < $dim; $j++) {
                $similarity += $newVector[$j] * $mean[$j];
            }
            if ($similarity > $maxSimilarity) {
                $maxSimilarity = $similarity;
                $assignedClusterIndex = $i;
            }
        }

        if ($maxSimilarity >= $similarityThreshold && $assignedClusterIndex !== null) {
            // Update the matching cluster with a running average.
            $cluster = $clusters[$assignedClusterIndex];
            $count = $cluster['count'];
            $oldMean = $cluster['mean'];
            $newMean = [];
            for ($j = 0; $j < $dim; $j++) {
                $newMean[$j] = ($oldMean[$j] * $count + $newVector[$j]) / ($count + 1);
            }
            // Normalize the new mean.
            $newMean = self::normalize($newMean);
            $clusters[$assignedClusterIndex]['mean'] = $newMean;
            $clusters[$assignedClusterIndex]['count'] = $count + 1;
        } else {
            // No suitable cluster found; create a new one.
            $clusters[] = [
                'mean' => $newVector,
                'count' => 1,
            ];
        }

        // Optionally, check for clusters that are too large and split them.
        foreach ($clusters as $i => $cluster) {
            if ($cluster['count'] > $maxCount) {
                // Split the cluster by perturbing its mean slightly.
                $oldMean = $cluster['mean'];
                $perturbation = 0.01;
                $newMean1 = [];
                $newMean2 = [];
                for ($j = 0; $j < $dim; $j++) {
                    $newMean1[$j] = $oldMean[$j] * (1 + $perturbation);
                    $newMean2[$j] = $oldMean[$j] * (1 - $perturbation);
                }
                $newMean1 = self::normalize($newMean1);
                $newMean2 = self::normalize($newMean2);
                // Roughly split the count between the two clusters.
                $half1 = floor($cluster['count'] / 2);
                $half2 = ceil($cluster['count'] / 2);
                $clusters[$i] = [
                    'mean' => $newMean1,
                    'count' => $half1,
                ];
                $clusters[] = [
                    'mean' => $newMean2,
                    'count' => $half2,
                ];
            }
        }

        // Optionally, check for clusters that are too small and merge them.
        // For each small cluster (count < $minCount), find its nearest neighbor (using Euclidean distance)
        // and merge them.
        for ($i = 0; $i < count($clusters); $i++) {
            if ($clusters[$i]['count'] < $minCount) {
                $minDistance = INF;
                $mergeIndex = null;
                $currentMean = $clusters[$i]['mean'];
                for ($j = 0; $j < count($clusters); $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    $distance = 0.0;
                    $otherMean = $clusters[$j]['mean'];
                    for ($d = 0; $d < $dim; $d++) {
                        $diff = $currentMean[$d] - $otherMean[$d];
                        $distance += $diff * $diff;
                    }
                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $mergeIndex = $j;
                    }
                }
                if ($mergeIndex !== null) {
                    // Merge cluster i into the nearest cluster.
                    $cluster1 = $clusters[$i];
                    $cluster2 = $clusters[$mergeIndex];
                    $totalCount = $cluster1['count'] + $cluster2['count'];
                    $mergedMean = [];
                    for ($d = 0; $d < $dim; $d++) {
                        $mergedMean[$d] = ($cluster1['mean'][$d] * $cluster1['count'] +
                                $cluster2['mean'][$d] * $cluster2['count']) / $totalCount;
                    }
                    $mergedMean = self::normalize($mergedMean);
                    $clusters[$mergeIndex]['mean'] = $mergedMean;
                    $clusters[$mergeIndex]['count'] = $totalCount;
                    unset($clusters[$i]);
                    $clusters = array_values($clusters);
                    // Restart the merging loop.
                    $i = -1;
                }
            }
        }

        return $clusters;
    }

    public static function getVectorEmbedding(string $text): array
    {
        // Get the vector embedding of text
        $embeddings = [];

        return $embeddings;
    }
}
