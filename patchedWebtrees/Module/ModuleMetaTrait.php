<?php

declare(strict_types=1);

namespace Cissee\WebtreesExt\Module;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Webtrees;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Collection;
use function GuzzleHttp\json_decode;

/**
 * Trait ModuleMetaTrait - default implementation of ModuleMetaInterface
 */
trait ModuleMetaTrait {

    public function customModuleVersion(): string {
        $metaData = $this->customModuleMetaData();
        return ($metaData != null) ? $metaData->version() : "";
    }

    public function minRequiredWebtreesVersion(): string {
        $metaData = $this->customModuleMetaData();
        return ($metaData != null) ? $metaData->minRequiredWebtreesVersion() : "";
    }

    public function minUnsupportedWebtreesVersion(): string {
        $metaData = $this->customModuleMetaData();
        return ($metaData != null) ? $metaData->minUnsupportedWebtreesVersion() : "";
    }

    public function customModuleLatestVersion(): string {
        //we're usually not interested in the latest overall version,
        //but in the latest version compatible with the current webtrees version
        //(we may indicate the latest overall version in the future,
        //in order to better motivate a webtrees upgrade?)
        $metaData = $this->customModuleMetaData(Webtrees::VERSION);

        if ($metaData === null) {
            return $this->customModuleVersion();
        }

        //append the changelog here (hacky, but easiest this way)
        $ret = $metaData->version();
        if (sizeof($metaData->changelog()) > 0) {
            $ret .= '; Changelog: ' . implode(" ", $metaData->changelog());
        }
        return $ret;
    }

    /**
     *
     * @return Collection<ModuleMetaData>
     */
    public function customModuleMetaDatas(): Collection {
        $json = $this->customModuleMetaDatasJson();
        return $this->decodeJsonToMetaDatas($json);
    }

    //adapted from ModuleCustomTrait

    /**
     *
     * @return Collection<ModuleMetaData>
     */
    public function customModuleLatestMetaDatas(): Collection {
        // No update URL provided.
        if ($this->customModuleLatestMetaDatasJsonUrl() === '') {
            return $this->customModuleMetaDatas();
        }

        $cache = Registry::cache()->file();

        //$this->name() may not be initialized at this point!
        $key = get_called_class() . '-latest-meta-data';

        return $cache->remember($key, function () {
                try {
                    $client = new Client([
                        'timeout' => 3,
                    ]);

                    $response = $client->get($this->customModuleLatestMetaDatasJsonUrl());

                    if ($response->getStatusCode() === StatusCodeInterface::STATUS_OK) {
                        $json = $response->getBody()->getContents();
                        return $this->decodeJsonToMetaDatas($json);
                    }
                    error_log("error retrieving metadata: " . $response->getStatusCode());

                } catch (ConnectException $ex) {
                    // Can't connect to the server?
                    error_log("error retrieving metadata: " . $ex);
                } catch (RequestException $ex) {
                    // Can't connect to the server?
                    error_log("error retrieving metadata: " . $ex);
                }

                return $this->customModuleMetaDatas();
            }, 3600); //ModuleCustomTrait has 1 day, we use 1 hour
    }

    public function customModuleMetaData(
        ?string $targetWebtreesVersion = null): ?ModuleMetaData {

        $current = $this->customModuleMetaDatas()
            ->sort(static function (ModuleMetaData $x, ModuleMetaData $y): int {
                //cannot use string comparator, we need alphanumeric comparator within the version parts,
                //i.e. php version_compare function
                //return $x->version() <=> $y->version();
                return version_compare($x->version(), $y->version());
            })
            //highest version is the current metadata
            ->last();

        if ($current === null) {
            //unexpected!
            return null;
        }

        if ($targetWebtreesVersion === null) {
            //check with data from server:
            //installed $current may not be up-to-date wrt actual range!
            $currentWithDataFromServer = $this->customModuleLatestMetaDatas()
                ->filter(static function (ModuleMetaData $x) use ($current): bool {
                    return ($x->version() === $current->version());
                })
                ->first();

            return ($currentWithDataFromServer !== null) ? $currentWithDataFromServer : $current;
        }

        return $this->customModuleLatestMetaDatas()
                ->sort(static function (ModuleMetaData $x, ModuleMetaData $y): int {
                    //cannot use string comparator, we need alphanumeric comparator within the version parts,
                    //i.e. php version_compare function
                    //return $x->version() <=> $y->version();
                    return version_compare($x->version(), $y->version());
                })
                ->filter(static function (ModuleMetaData $x) use ($current, $targetWebtreesVersion): bool {
                    //everything up to current version is irrelevant for the cumulative changelog
                    //if ($x->version() <= $current->version()) {
                    if (version_compare($x->version(), $current->version()) < 1) {
                        return false;
                    }

                    //everything out of range wrt target version is also irrelevant
                    //$isInRange = (
                    //    ($x->minRequiredWebtreesVersion() <= $targetWebtreesVersion) &&
                    //    ($targetWebtreesVersion < $x->minUnsupportedWebtreesVersion()));
                    $isInRange = (
                        (version_compare($x->minRequiredWebtreesVersion(), $targetWebtreesVersion) < 1) &&
                        (version_compare($targetWebtreesVersion, $x->minUnsupportedWebtreesVersion()) < 0));

                    return $isInRange;
                })
                //highest version is the target metadata, but we have to create the cumulative changelog
                //->last();
                ->reduce(static function (?ModuleMetaData $carry, ModuleMetaData $item): ModuleMetaData {
                    return $item->prependChangelogFrom($carry);
                }, null);
    }

    /**
     *
     * @param string $jsonArray
     * @return Collection<ModuleMetaData>
     */
    public function decodeJsonToMetaDatas(string $jsonArray): Collection {
        try {
            $ret = [];
            $jsonArrayDecoded = json_decode($jsonArray, true);
            foreach ($jsonArrayDecoded as $json) {
                $version = $json['version'];
                $minRequiredWebtreesVersion = $json['from'];
                $minUnsupportedWebtreesVersion = $json['to'];
                $changelogArray = array_key_exists('changelog', $json) ? $json['changelog'] : [];
                $changelog = [];
                foreach ($changelogArray as $changelogEntry) {
                    $changelog[$changelogEntry] = $changelogEntry;
                }
                $metaData = new ModuleMetaData($version, $minRequiredWebtreesVersion, $minUnsupportedWebtreesVersion, $changelog);
                $ret [] = $metaData;
            }
            return new Collection($ret);
        } catch (\Exception $e) {
            error_log("error decoding json: " . $e->getTraceAsString());
            return new Collection();
        }
    }

}
