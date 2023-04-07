# PocketMine-MP Timings Viewer

Source code for https://timings.pmmp.io

This project was originally forked from the original (v1) version of [aikar/timings](https://github.com/aikar/timings).

## Deployment
Deploying PocketMine-MP Timings is easy:

Pre-requisites:
- git
- docker-ce-cli
- docker-compose

Steps:
1. Clone the repo
2. cd into it
3. Copy `example.env` to `.env` **and edit it to change the db passwords**
4. Run `docker-compose up -d`

And that's it! The server is now accessible on http://localhost:7081.

## Differences compared to Timings v1
This timings viewer has significant improvements compared to aikar/timings v1, including:

- UI and layout improvements
- Support for displaying timings as a tree (requires PocketMine-MP 4.19.0 or newer)
- New metrics added, such as Peak time and Violations
- Manual paste support
- Uses a MySQL database to store timings reports, instead of abusing https://paste.ubuntu.com

## Why not use Timings v2?

Due to lack of any kind of specification or reference implementation for Timings v2, implementing Timings v2 did not make much sense.
In addition, the Timings v2 format is specifically described as "proprietary" and "subject to change without notice", which did not give the PMMP Team a lot of motivation to attempt to implement it ([source](https://github.com/aikar/timings#timings-file-format)).

Timings v1 can be altered to support being displayed as a tree with minimal changes to the timings format, allowing us to develop a version of Timings which accepts both legacy v1 reports and newer reports with tree metadata. Newer reports can also be displayed by a legacy v1 viewer with no changes.

## File format
Coming soon: documentation. Watch this space...

## License
Timings v1 (c) Daniel Ennis (Aikar) 2014-2017

PocketMine-MP Timings (c) PMMP Team 2017-2023

This project is licensed under [MIT](/LICENSE).
